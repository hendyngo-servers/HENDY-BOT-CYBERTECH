<?php
declare(strict_types=1);
namespace Hendy;
use RuntimeException;
interface StoreConnector { public function provider(): string; public function validateConfiguration(array $config): void; public function capabilities(): array; }
abstract class BaseStoreConnector implements StoreConnector
{
    public function validateConfiguration(array $config): void { foreach($this->requiredKeys() as $key) if(empty($config[$key])||!is_string($config[$key])) throw new RuntimeException('Thiếu cấu hình nhà phát hành: '.$key); }
    abstract protected function requiredKeys(): array;
    public function capabilities(): array { return ['metadata_read'=>true,'upload_binary'=>false,'submit_for_review'=>false,'publish'=>false]; }
}
final class AppleAppStoreConnector extends BaseStoreConnector { public function provider():string{return 'app_store_connect';} protected function requiredKeys():array{return ['issuer_id','key_id','private_key_secret_ref'];} }
final class GooglePlayConnector extends BaseStoreConnector { public function provider():string{return 'google_play';} protected function requiredKeys():array{return ['service_account_secret_ref','package_name'];} }
final class MicrosoftStoreConnector extends BaseStoreConnector { public function provider():string{return 'microsoft_store';} protected function requiredKeys():array{return ['client_id','tenant_id','client_secret_ref','product_id'];} }
