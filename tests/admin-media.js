const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const previewCode = fs.readFileSync(path.join(__dirname, '../assets/admin-media-previews.js'), 'utf8');
const maintenanceCode = fs.readFileSync(path.join(__dirname, '../assets/admin-media-maintenance.js'), 'utf8');
test('grid and details use bounded previews without modifying insertion/original URLs', () => {
 function Attachment() {} Attachment.prototype.imageSize = () => ({url: 'native.jpg'});
 Attachment.Details = function() {}; Attachment.Details.prototype.template = data => data;
 Attachment.Details.prototype.render = function(data) {return this.template(data);};
 const wp = {media: {view: {Attachment}}};
 const context = {window: {wp}, wp}; vm.runInNewContext(previewCode, context);
 // WordPress media-grid installs this subclass after the adapter has loaded.
 Attachment.Details.TwoColumn = function() {};
 Attachment.Details.TwoColumn.prototype = Object.create(Attachment.Details.prototype);
 Attachment.Details.TwoColumn.prototype.template = data => data;
 const data = {type: 'image', url: 'original.jpg', sizes: {full: {url: 'original.jpg'}, medium: {url: 'native-medium.jpg'}}, schrackMediaPreview: {small: {url: 'small.jpg', width: 300}, detail: {url: 'detail.jpg', width: 1024}}};
 const view = new Attachment(); view.model = {get: key => data[key]};
 assert.equal(view.imageSize().url, 'small.jpg');
 const first = Attachment.prototype.imageSize; vm.runInNewContext(previewCode, context); assert.equal(first, Attachment.prototype.imageSize);
 for (const View of [Attachment.Details, Attachment.Details.TwoColumn]) {
  const view = new View(), nativeTemplate = view.template;
  const result = view.render(data);
  assert.equal(result.sizes.full.url, 'detail.jpg'); assert.equal(result.size.url, 'detail.jpg');
  assert.equal(data.sizes.full.url, 'original.jpg'); assert.equal(data.url, 'original.jpg'); assert.equal(result.sizes.medium.url, 'native-medium.jpg');
  assert.equal(view.template, nativeTemplate); assert.equal(Object.hasOwn(view, 'template'), false);
 }
 view.model = {get: () => undefined}; assert.equal(view.imageSize().url, 'native.jpg');
});
test('late two-column templates bound image URLs before insertion while keeping original controls', () => {
 const document = {createElement(tag) {
  assert.equal(tag, 'template', 'preview markup must remain inert until rewritten');
  let html = '', images = [];
  return {set innerHTML(value) {
   html = value; images = [...html.matchAll(/<img\b[^>]*>/g)].map(match => {
    const attributes = new Map([...match[0].matchAll(/([\w-]+)="([^"]*)"/g)].map(item => [item[1], item[2]]));
    return {setAttribute: (key, value) => attributes.set(key, value), removeAttribute: key => attributes.delete(key), serialize: () => '<img ' + [...attributes].map(([key, value]) => `${key}="${value}"`).join(' ') + '>'};
   });
  }, get innerHTML() {let i = 0; return html.replace(/<img\b[^>]*>/g, () => images[i++].serialize());}, content: {querySelectorAll(selector) {assert.equal(selector, 'img'); return images;}}};
 }};
 function Attachment() {} Attachment.prototype.imageSize = () => ({});
 Attachment.Details = function() {};
 Attachment.Details.prototype.render = function(data) {return this.template(data);};
 const wp = {media: {view: {Attachment}}}, context = {window: {wp}, wp, document};
 vm.runInNewContext(previewCode, context);
 function TwoColumn() {} TwoColumn.prototype = Object.create(Attachment.Details.prototype);
 TwoColumn.prototype.template = data => `<img src="${data.url}" srcset="${data.url} 2x" sizes="100vw" alt="preview"><a href="${data.url}" download>Original</a><input value="${data.url}">`;
 Attachment.Details.TwoColumn = TwoColumn;
 const data = {type: 'image', url: 'original.jpg', sizes: {full: {url: 'original.jpg'}}, schrackMediaPreview: {detail: {url: 'detail.jpg', width: 1024}}};
 const view = new TwoColumn(), template = view.template, render = Attachment.Details.prototype.render;
 const result = view.render(data);
 assert.match(result, /<img src="detail.jpg" alt="preview">/);
 assert.doesNotMatch(result, /srcset=|sizes=/);
 assert.match(result, /href="original.jpg" download/); assert.match(result, /value="original.jpg"/);
 assert.equal(data.url, 'original.jpg'); assert.equal(data.sizes.full.url, 'original.jpg');
 assert.equal(view.template, template); assert.equal(Object.hasOwn(view, 'template'), false);
 vm.runInNewContext(previewCode, context); assert.equal(Attachment.Details.prototype.render, render);
 view.template = () => {throw Error('template failed');}; const failingTemplate = view.template;
 assert.throws(() => view.render(data), /template failed/); assert.equal(view.template, failingTemplate);
 const audio = {...data, type: 'audio'}; assert.match(new TwoColumn().render(audio), /src="original.jpg" srcset=/);
});
class Element {
 constructor(tag) {this.tag = tag; this.children = []; this.dataset = {}; this.style = {}; this.textContent = ''; this.events = {}; this.disabled = false; this.isConnected = true;}
 appendChild(child) {this.children.push(child); return child;}
 replaceChildren(...children) {this.children = children;}
 contains(element) {return this === element || this.children.some(child => child.contains(element));}
 querySelectorAll(selector) {return this.children.flatMap(child => [...(selector === 'a' && child.tag === 'a' ? [child] : []), ...child.querySelectorAll(selector)]);}
 addEventListener(event, callback) {this.events[event] = callback;}
 focus(options) {this.document.activeElement = this; this.focusOptions = options;}
 click() {if (!this.disabled) this.events.click?.();}
}
test('WooCommerce gallery selection receives a render clone; native insertion remains unchanged', () => {
 function Attachment() {} Attachment.prototype.imageSize = () => ({});
 function Selection(models) {this.models = models;} Selection.prototype.map = function(callback) {return this.models.map(callback);};
 function Model(data) {this.data = data;} Model.prototype.toJSON = function() {return this.data;};
 const wp = {media: {view: {Attachment}, model: {Attachment: Model, Selection}, frames: {}}};
 vm.runInNewContext(previewCode, {window: {wp}, wp});
 const data = {id: 7, type: 'image', url: 'original.jpg', sizes: {full: {url: 'original.jpg'}, thumbnail: {url: 'native.jpg'}}, schrackMediaPreview: {small: {url: 'small.jpg'}}};
 const model = new Model(data), gallery = new Selection([model]), insertion = new Selection([model]);
 wp.media.frames.product_gallery = {state: () => ({get: () => gallery})};
 assert.equal(gallery.map(attachment => attachment.toJSON())[0].sizes.thumbnail.url, 'small.jpg');
 assert.equal(insertion.map(attachment => attachment.toJSON())[0].sizes.thumbnail.url, 'native.jpg');
 assert.equal(model.toJSON().sizes.thumbnail.url, 'native.jpg');
 assert.equal(model.toJSON().url, 'original.jpg'); assert.equal(gallery.map(attachment => attachment.toJSON())[0].sizes.full.url, 'original.jpg');
 assert.throws(() => gallery.map(() => {throw Error('renderer failure');}), /renderer failure/);
 assert.equal(model.toJSON().sizes.thumbnail.url, 'native.jpg');
 wp.media.frames.product_gallery.state = () => null;
 assert.equal(insertion.map(attachment => attachment.toJSON())[0].sizes.thumbnail.url, 'native.jpg');
});
function page() {
 const names = ['maintenance', 'message', 'state', 'report', 'previous', 'next'];
 const nodes = Object.fromEntries(names.map(name => [name, new Element(name)]));
 const buttons = ['scan', 'repair', 'pause', 'resume'].map(operation => {const button = new Element('button'); button.dataset.mediaOperation = operation; return button;});
 nodes.maintenance.querySelectorAll = () => buttons;
 const events = {}, timers = new Map(), requests = []; let clockId = 0;
 const document = {hidden: false, activeElement: null, body: new Element('body'), getElementById: id => nodes[id.replace('schrack-media-', '')], createElement: tag => {const node = new Element(tag); node.document = document; return node;}, addEventListener: (event, callback) => {events[event] = callback;}};
 buttons.forEach(button => {button.document = document;});
 const window = {scrollX: 12, scrollY: 120, scrollTo(x, y) {this.scrollX = x; this.scrollY = y;}};
 const context = {window, document, URLSearchParams, AbortController, schrackMediaMaintenance: {ajax: '/ajax', nonce: 'nonce'}, setTimeout: (fn, delay) => {const id = ++clockId; timers.set(id, {fn, delay}); return id;}, clearTimeout: id => timers.delete(id), fetch: (url, options) => new Promise((resolve, reject) => {requests.push({operation: options.body.get('operation'), before: options.body.get('before'), resolve, reject});})};
 vm.runInNewContext(maintenanceCode, context);
 const settle = async (data, ok = true) => {requests.at(-1).resolve({ok, json: async () => ({success: ok, data})}); await new Promise(resolve => setImmediate(resolve));};
 const fail = async () => {requests.at(-1).reject(new Error('network')); await new Promise(resolve => setImmediate(resolve));};
 const advance = () => {const entry = [...timers].find(([, timer]) => timer.delay !== 30000); if (entry) {timers.delete(entry[0]); entry[1].fn();} return entry?.[1].delay;};
 return {nodes, buttons, document, window, events, timers, requests, settle, fail, advance};
}
const state = status => ({id: 'job', status, mode: 'scan', scanned: 1, issues: 1, source_copies: 0, identical_copies: 0, repaired: 0, skipped: 0});
const row = {id: 7, edit_url: '/edit/7', title: '<script>alert(1)</script>', file: 'x.jpg', dimensions: [5000, 5000], bytes: 600000, issues: ['Miniatură lipsă'], source_hash_matches: [], file_hash_matches: [], references: {known: [], unknown: true}, repair: 'pending'};
test('polling never overlaps, suspends while hidden and stops on completion', async () => {
 const p = page(); assert.equal(p.requests.length, 1); p.buttons[0].click(); assert.equal(p.requests.length, 1);
 await p.settle({state: state('running'), rows: [], next: 0});
 p.document.hidden = true; p.events.visibilitychange(); assert.equal(p.advance(), undefined);
 p.document.hidden = false; p.events.visibilitychange(); assert.equal(p.advance(), 0); assert.equal(p.requests.length, 2);
 p.events.visibilitychange(); assert.equal(p.advance(), undefined); assert.equal(p.requests.length, 2);
 await p.settle({state: state('complete'), rows: [], next: 0}); assert.equal(p.advance(), undefined);
});
test('read errors back off and failed mutations are never repeated automatically', async () => {
 const p = page(); await p.fail(); assert.match(p.nodes.message.textContent, /network/); assert.equal(p.advance(), 10000);
 await p.fail(); assert.equal(p.advance(), 20000);
 await p.settle({state: {}, rows: [], next: 0}); p.buttons[0].click(); assert.equal(p.requests.at(-1).operation, 'scan');
 await p.fail(); assert.match(p.nodes.message.textContent, /Verifică starea/); assert.equal(p.advance(), 1000);
 assert.equal(p.requests.at(-1).operation, 'status'); assert.equal(p.requests.filter(request => request.operation === 'scan').length, 1);
});
test('report renders literal text, preserves link focus and pagination during updates', async () => {
 const p = page(); await p.settle({state: state('running'), rows: [row], next: 7});
 const link = p.nodes.report.querySelectorAll('a')[0]; assert.equal(link.textContent, '<script>alert(1)</script> (#7)'); link.focus({});
 p.advance(); await p.settle({state: state('running'), rows: [{...row, repair: 'repaired'}], next: 7});
 const newLink = p.nodes.report.querySelectorAll('a')[0]; assert.equal(p.document.activeElement, newLink); assert.equal(newLink.focusOptions.preventScroll, true);
 p.nodes.next.click(); assert.equal(p.requests.at(-1).before, '7'); await p.settle({state: state('running'), rows: [], next: 0});
 p.advance(); assert.equal(p.requests.at(-1).before, '7'); await p.settle({state: state('complete'), rows: [], next: 0});
 p.nodes.previous.click(); assert.equal(p.requests.at(-1).before, '0'); await p.settle({state: state('complete'), rows: [row], next: 7});
});
test('polling restores a temporarily disabled control focus and keeps scroll position', async () => {
 const p = page(); await p.settle({state: state('running'), rows: [], next: 0});
 p.buttons[2].focus({}); p.advance(); p.document.activeElement = p.document.body;
 const nativeReplace = p.nodes.report.replaceChildren.bind(p.nodes.report);
 p.nodes.report.replaceChildren = (...children) => {nativeReplace(...children); p.window.scrollY = 0;};
 await p.settle({state: state('running'), rows: [row], next: 0});
 assert.equal(p.document.activeElement, p.buttons[2]); assert.equal(p.window.scrollY, 120);
});
