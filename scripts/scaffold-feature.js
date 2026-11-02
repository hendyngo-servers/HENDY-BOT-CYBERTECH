import fs from "node:fs";
import path from "node:path";
import {fileURLToPath} from "node:url";
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),"..");
const allowed=new Set(["api","bot","mini-app"]),pattern=/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/;
export function validateFeatureName(name){
 const value=String(name||"").trim();
 if(!pattern.test(value)||value.length>40)throw new Error("Tên feature phải là slug chữ thường, ví dụ order-history.");
 return value;
}
export function buildFeatureFiles({name,category="api",description=""}){
 const slug=validateFeatureName(name);
 if(!allowed.has(category))throw new Error("Loại feature phải là api, bot hoặc mini-app.");
 const title=slug.split("-").map(part=>part[0].toUpperCase()+part.slice(1)).join(" ");
 const summary=String(description||"Mô tả mục tiêu, quyền truy cập và luồng nghiệp vụ.").trim().slice(0,500);
 const base=category==="mini-app"?path.posix.join("mini-app/src/features",slug):path.posix.join("src/features",slug);
 const files={};
 files[path.posix.join(base,"README.md")]=["# "+title,"","Loại: "+category,"",summary,"","## Checklist trước khi bật",
 "- [ ] Xác định quyền người dùng/Admin.","- [ ] Xác thực và kiểm tra dữ liệu đầu vào.",
 "- [ ] Thêm migration D1 nếu cần thay đổi dữ liệu.","- [ ] Thêm unit/integration tests.",
 "- [ ] Cập nhật API và tài liệu cấu hình.","- [ ] Không đưa secret vào source code hoặc log.",""].join("\n");
 files[path.posix.join(base,"feature.json")]=JSON.stringify({name:slug,title,category,enabled:false,createdBy:"github-actions-scaffold",description:summary},null,2)+"\n";
 files[path.posix.join(base,"index.js")]=category==="mini-app"
 ?["// Add the approved UI module and permission checks here.","export function mount"+title.replace(/[^A-Za-z0-9]/g,"")+"Feature(root,context){",
 " if(!root)throw new Error('Feature root element is required.');"," root.dataset.feature='"+slug+"';",
 " return ()=>{delete root.dataset.feature;};","}",""].join("\n")
 :["// Register explicitly after review; generated features are not auto-enabled.","export async function register(context){",
 " if(!context)throw new Error('Feature context is required.');"," return {name:'"+slug+"',enabled:false};","}",""].join("\n");
 return files;
}
export function writeFeature(options,cwd=root){
 const files=buildFeatureFiles(options),written=[];
 for(const [relative,content] of Object.entries(files)){
  const destination=path.resolve(cwd,relative),base=path.resolve(cwd);
  if(!destination.startsWith(base+path.sep))throw new Error("Đường dẫn scaffold vượt khỏi repository.");
  if(fs.existsSync(destination))throw new Error("Không ghi đè tệp đã tồn tại: "+relative);
  fs.mkdirSync(path.dirname(destination),{recursive:true});
  const fd=fs.openSync(destination,"wx");try{fs.writeFileSync(fd,content,"utf8");}finally{fs.closeSync(fd);}
  written.push(relative);
 }
 return written;
}
function parseArgs(argv){const result={};for(let i=0;i<argv.length;i++)if(argv[i].startsWith("--"))result[argv[i].slice(2)]=argv[i+1]||"";return result;}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
 try{const input=parseArgs(process.argv.slice(2));const files=writeFeature({name:input.name,category:input.category||"api",description:input.description});
  console.log("Đã tạo scaffold ở trạng thái disabled:");for(const file of files)console.log(" - "+file);
 }catch(error){console.error("Không thể tạo scaffold:",error.message);process.exitCode=1;}
}
