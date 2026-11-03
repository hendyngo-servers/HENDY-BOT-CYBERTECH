<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

/**
 * Rated, bilateral matchmaking results.
 *
 * A player may report a score, but it only affects ratings and points after
 * the opponent confirms it. A disputed result is frozen for admin review.
 * Points are internal leaderboard points; this service never credits wallet
 * balances or cash.
 */
final class MatchmakingService
{
    public static function ensureStats(string $userId): array
    {
        Database::run(
            'INSERT INTO matchmaking_player_stats (user_id) VALUES (?) ON CONFLICT (user_id) DO NOTHING',
            [$userId]
        );
        return Database::one(
            'SELECT user_id, rating, points, matches_played, wins, losses, draws FROM matchmaking_player_stats WHERE user_id = ?',
            [$userId]
        ) ?? [
            'user_id' => $userId,
            'rating' => 1000,
            'points' => 0,
            'matches_played' => 0,
            'wins' => 0,
            'losses' => 0,
            'draws' => 0,
        ];
    }

    public static function report(int $matchId, string $reporterId, int $scoreOne, int $scoreTwo): array
    {
        self::assertScore($scoreOne);
        self::assertScore($scoreTwo);

        return Database::transaction(function () use ($matchId, $reporterId, $scoreOne, $scoreTwo): array {
            $match = Database::one(
                'SELECT id, player_one, player_two, mode, status FROM matchmaking_matches WHERE id = ? FOR UPDATE',
                [$matchId]
            );
            if ($match === null || !in_array($reporterId, [$match['player_one'], $match['player_two']], true)) {
                throw new RuntimeException('Không tìm thấy lượt ghép hoặc bạn không tham gia lượt này.');
            }
            if ($match['status'] !== 'matched') {
                throw new RuntimeException('Lượt ghép không còn nhận kết quả mới.');
            }

            $existing = Database::one('SELECT id, status FROM matchmaking_results WHERE match_id = ? FOR UPDATE', [$matchId]);
            if ($existing !== null) {
                throw new RuntimeException('Lượt ghép đã có kết quả báo cáo. Hãy chờ xác nhận hoặc quản trị viên xử lý.');
            }

            $result = Database::one(
                "INSERT INTO matchmaking_results (match_id, reported_by, score_one, score_two, status) VALUES (?, ?, ?, ?, 'pending') RETURNING id, match_id, reported_by, score_one, score_two, status, created_at",
                [$matchId, $reporterId, $scoreOne, $scoreTwo]
            );
            $opponentId = $match['player_one'] === $reporterId ? $match['player_two'] : $match['player_one'];
            return [
                'result' => $result,
                'opponentId' => $opponentId,
                'playerOneId' => $match['player_one'],
                'playerTwoId' => $match['player_two'],
            ];
        });
    }

    public static function confirm(int $matchId, string $actorId, bool $approved): array
    {
        return Database::transaction(function () use ($matchId, $actorId, $approved): array {
            $match = Database::one(
                'SELECT id, player_one, player_two, mode, status FROM matchmaking_matches WHERE id = ? FOR UPDATE',
                [$matchId]
            );
            if ($match === null || !in_array($actorId, [$match['player_one'], $match['player_two']], true)) {
                throw new RuntimeException('Không tìm thấy lượt ghép hoặc bạn không tham gia lượt này.');
            }

            $result = Database::one(
                'SELECT * FROM matchmaking_results WHERE match_id = ? FOR UPDATE',
                [$matchId]
            );
            if ($result === null || $result['status'] !== 'pending') {
                throw new RuntimeException('Không có kết quả đang chờ xác nhận.');
            }
            if ($result['reported_by'] === $actorId) {
                throw new RuntimeException('Người báo cáo không thể tự xác nhận kết quả của mình.');
            }

            if (!$approved) {
                Database::run(
                    "UPDATE matchmaking_results SET status = 'disputed', confirmed_by = ?, confirmed_at = NOW() WHERE id = ?",
                    [$actorId, $result['id']]
                );
                Database::run("UPDATE matchmaking_matches SET status = 'disputed' WHERE id = ?", [$matchId]);
                Database::run(
                    "UPDATE matchmaking_queue SET status = 'disputed', updated_at = NOW() WHERE match_id = ?",
                    [$matchId]
                );
                return ['status' => 'disputed', 'matchId' => (string) $matchId];
            }

            return self::finalizeLocked($match, $result, $actorId);
        });
    }

    /**
     * Admin resolution for a disputed match. Caller must perform admin auth.
     */
    public static function resolveDispute(int $matchId, string $adminId, int $scoreOne, int $scoreTwo): array
    {
        self::assertScore($scoreOne);
        self::assertScore($scoreTwo);

        return Database::transaction(function () use ($matchId, $adminId, $scoreOne, $scoreTwo): array {
            $match = Database::one(
                'SELECT id, player_one, player_two, mode, status FROM matchmaking_matches WHERE id = ? FOR UPDATE',
                [$matchId]
            );
            if ($match === null || $match['status'] !== 'disputed') {
                throw new RuntimeException('Không tìm thấy lượt ghép đang tranh chấp.');
            }
            $result = Database::one(
                "SELECT * FROM matchmaking_results WHERE match_id = ? AND status = 'disputed' FOR UPDATE",
                [$matchId]
            );
            if ($result === null) {
                throw new RuntimeException('Không tìm thấy bản ghi kết quả tranh chấp.');
            }

            Database::run(
                'UPDATE matchmaking_results SET score_one = ?, score_two = ?, status = \'pending\' WHERE id = ?',
                [$scoreOne, $scoreTwo, $result['id']]
            );
            $updated = Database::one('SELECT * FROM matchmaking_results WHERE id = ?', [$result['id']]);
            return self::finalizeLocked($match, $updated ?? $result, $adminId, true);
        });
    }

    private static function finalizeLocked(array $match, array $result, string $confirmedBy, bool $adminResolution = false): array
    {
        $scoreOne = (int) $result['score_one'];
        $scoreTwo = (int) $result['score_two'];
        $playerOne = (string) $match['player_one'];
        $playerTwo = (string) $match['player_two'];

        Database::run(
            'INSERT INTO matchmaking_player_stats (user_id) VALUES (?), (?) ON CONFLICT (user_id) DO NOTHING',
            [$playerOne, $playerTwo]
        );

        $statsRows = Database::all(
            'SELECT user_id, rating FROM matchmaking_player_stats WHERE user_id IN (?, ?) ORDER BY user_id FOR UPDATE',
            [$playerOne, $playerTwo]
        );
        $ratings = [];
        foreach ($statsRows as $row) {
            $ratings[(string) $row['user_id']] = (int) $row['rating'];
        }
        $ratingOne = $ratings[$playerOne] ?? 1000;
        $ratingTwo = $ratings[$playerTwo] ?? 1000;

        $isRated = $match['mode'] === 'quick';
        $delta = $isRated ? self::calculateElo($ratingOne, $ratingTwo, $scoreOne, $scoreTwo) : [0, 0];
        $deltaOne = $delta[0];
        $deltaTwo = $delta[1];

        if ($scoreOne > $scoreTwo) {
            $winsOne = 1; $lossesOne = 0; $drawsOne = 0;
            $winsTwo = 0; $lossesTwo = 1; $drawsTwo = 0;
            $pointsOne = $isRated ? 10 : 1;
            $pointsTwo = $isRated ? 3 : 1;
            $winnerId = $playerOne;
        } elseif ($scoreTwo > $scoreOne) {
            $winsOne = 0; $lossesOne = 1; $drawsOne = 0;
            $winsTwo = 1; $lossesTwo = 0; $drawsTwo = 0;
            $pointsOne = $isRated ? 3 : 1;
            $pointsTwo = $isRated ? 10 : 1;
            $winnerId = $playerTwo;
        } else {
            $winsOne = 0; $lossesOne = 0; $drawsOne = 1;
            $winsTwo = 0; $lossesTwo = 0; $drawsTwo = 1;
            $pointsOne = $isRated ? 5 : 1;
            $pointsTwo = $isRated ? 5 : 1;
            $winnerId = null;
        }

        Database::run(
            'UPDATE matchmaking_player_stats SET rating = rating + ?, points = points + ?, matches_played = matches_played + 1, wins = wins + ?, losses = losses + ?, draws = draws + ?, updated_at = NOW() WHERE user_id = ?',
            [$deltaOne, $pointsOne, $winsOne, $lossesOne, $drawsOne, $playerOne]
        );
        Database::run(
            'UPDATE matchmaking_player_stats SET rating = rating + ?, points = points + ?, matches_played = matches_played + 1, wins = wins + ?, losses = losses + ?, draws = draws + ?, updated_at = NOW() WHERE user_id = ?',
            [$deltaTwo, $pointsTwo, $winsTwo, $lossesTwo, $drawsTwo, $playerTwo]
        );

        Database::run(
            "UPDATE matchmaking_results SET status = 'confirmed', confirmed_by = ?, rating_delta_one = ?, rating_delta_two = ?, points_awarded_one = ?, points_awarded_two = ?, confirmed_at = NOW() WHERE id = ?",
            [$confirmedBy, $deltaOne, $deltaTwo, $pointsOne, $pointsTwo, $result['id']]
        );
        Database::run(
            "UPDATE matchmaking_matches SET status = 'completed', finished_at = NOW() WHERE id = ?",
            [$match['id']]
        );
        Database::run(
            "UPDATE matchmaking_queue SET status = 'completed', updated_at = NOW() WHERE match_id = ?",
            [$match['id']]
        );

        $newOne = Database::one('SELECT rating, points FROM matchmaking_player_stats WHERE user_id = ?', [$playerOne]);
        $newTwo = Database::one('SELECT rating, points FROM matchmaking_player_stats WHERE user_id = ?', [$playerTwo]);

        return [
            'status' => 'completed',
            'matchId' => (string) $match['id'],
            'winnerId' => $winnerId,
            'draw' => $winnerId === null,
            'ratingDeltaOne' => $deltaOne,
            'ratingDeltaTwo' => $deltaTwo,
            'ratingOne' => (int) ($newOne['rating'] ?? $ratingOne),
            'ratingTwo' => (int) ($newTwo['rating'] ?? $ratingTwo),
            'pointsAwardedOne' => $pointsOne,
            'pointsAwardedTwo' => $pointsTwo,
            'adminResolved' => $adminResolution,
        ];
    }

    public static function calculateElo(int $ratingOne, int $ratingTwo, int $scoreOne, int $scoreTwo, int $kFactor = 32): array
    {
        self::assertScore($scoreOne);
        self::assertScore($scoreTwo);
        if ($kFactor < 1 || $kFactor > 128) {
            throw new RuntimeException('Hệ số Elo không hợp lệ.');
        }

        $expectedOne = 1 / (1 + (10 ** (($ratingTwo - $ratingOne) / 400)));
        $actualOne = $scoreOne > $scoreTwo ? 1.0 : ($scoreOne < $scoreTwo ? 0.0 : 0.5);
        $deltaOne = (int) round($kFactor * ($actualOne - $expectedOne));
        return [$deltaOne, -$deltaOne];
    }

    public static function leaderboard(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return Database::all(
            'SELECT s.user_id, COALESCE(NULLIF(u.name, \'\'), NULLIF(u.username, \'\'), \'Người chơi Hendy\') AS display_name, s.rating, s.points, s.matches_played, s.wins, s.losses, s.draws FROM matchmaking_player_stats s JOIN users u ON u.user_id = s.user_id ORDER BY s.rating DESC, s.points DESC, s.matches_played ASC LIMIT ' . $limit
        );
    }

    private static function assertScore(int $score): void
    {
        if ($score < 0 || $score > 999) {
            throw new RuntimeException('Tỷ số phải là số nguyên từ 0 đến 999.');
        }
    }
}
