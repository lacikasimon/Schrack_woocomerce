// Run against the inspected installed CookieAdmin source, without WordPress or a database.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {spawnSync} = require('node:child_process');
const source = process.argv[2];
if (!source) throw Error('Usage: node tests/native-consent-text.js /path/to/cookieadmin/assets/js/consent.js');
const php = spawnSync('php', ['-r', `define('ABSPATH', '/source-only/');function apply_filters($name,$value){return $value;}
require $argv[1];
$method=new ReflectionMethod('Schrack_Frontend_Performance','preserve_rendered_consent_text');
echo $method->invoke(new Schrack_Frontend_Performance(),file_get_contents($argv[2]));`,
path.join(__dirname,'../includes/class-schrack-frontend-performance.php'),source], {encoding:'utf8'});
assert.equal(php.status,0,php.stderr);
const native = fs.readFileSync(source,'utf8');
const transformed = php.stdout;
assert.notEqual(transformed,native,'The fixture must be the inspected native source.');
const start = transformed.indexOf('\tfor(data in cookieadmin_policy){');
const end = transformed.indexOf('\tif(!!cookieadmin_policy.cookieadmin_position',start);
assert.ok(start>=0&&end>start);
const policyLoop = transformed.slice(start,end);
function element(value, inside=true) {
 let html=value, writes=0, child={};
 return {style:{}, closest:()=>inside?{}:null,
 get innerHTML(){return html;}, set innerHTML(v){html=String(v);writes++;child={};},
 get writes(){return writes;}, get child(){return child;}};
}
function run(policy, elements, early=true) {
 vm.runInNewContext(policyLoop,{cookieadmin_policy:policy,document:{
 getElementById:id=>id==='schrack-early-consent-banner'?(early?{}:null):(elements[id]||null),
 getElementsByClassName:()=>[]}});
}
test('native startup retains identical early notice/title/button child nodes',()=>{
 const elements={cookieadmin_notice:element('<b>Cookie-uri</b>'),cookieadmin_title:element('Preferințe'),cookieadmin_accept:element('Accept')};
 const children=Object.values(elements).map(e=>e.child);
 run(Object.fromEntries(Object.entries(elements).map(([key,e])=>[key,e.innerHTML])),elements);
 Object.values(elements).forEach((e,i)=>{assert.equal(e.writes,0);assert.equal(e.child,children[i]);});
});
test('changed localized text still replaces early markup through the native assignment',()=>{
 const e=element('Old');run({cookieadmin_notice:'New <em>policy</em>'},{cookieadmin_notice:e});
 assert.equal(e.writes,1);assert.equal(e.innerHTML,'New <em>policy</em>');
});
test('modal elements and pages without early bootstrap retain native writes',()=>{
 for(const [inside,early] of [[false,true],[true,false]]){
 const e=element('Unchanged',inside);run({cookieadmin_notice:'Unchanged'},{cookieadmin_notice:e},early);assert.equal(e.writes,1);
 }
});
test('native colors and non-string values continue to update',()=>{
 const e=element('Old');run({cookieadmin_notice:0,cookieadmin_notice_color:'#123456',cookieadmin_notice_bg_color:'#ffffff',cookieadmin_notice_border_color:'#eeeeee'},{cookieadmin_notice:e});
 assert.equal(e.innerHTML,'0');assert.equal(e.writes,1);
 assert.deepEqual(e.style,{color:'#123456',backgroundColor:'#ffffff',borderColor:'#eeeeee'});
});
