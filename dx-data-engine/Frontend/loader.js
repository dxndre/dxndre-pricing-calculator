/** Shared public-static loader. No auth, polling, service worker or dynamic API. */
const memory = new Map();
export class DataUnavailable extends Error {
	constructor(message, closed = false) { super(message); this.closed = closed; }
}
export async function loadDataset(manifestURL, { id, schemaVersion, validate, signal, timeout = 8000, maxBytes = 8388608 } = {}) {
	const url = new URL(manifestURL, location.href);
	if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) throw new DataUnavailable('Invalid dataset origin', true);
	const key = `${id}:${schemaVersion}:${url.href}`;
	if (!memory.has(key)) memory.set(key, retrieve(url, {id, schemaVersion, validate, timeout, maxBytes, key}).catch(error => { memory.delete(key); throw error; }));
	if (!signal) return memory.get(key);
	if (signal.aborted) throw new DOMException('Aborted', 'AbortError');
	return new Promise((resolve,reject) => {
		const abort = () => reject(new DOMException('Aborted', 'AbortError'));
		signal.addEventListener('abort',abort,{once:true});
		memory.get(key).then(resolve,reject).finally(()=>signal.removeEventListener('abort',abort));
	});
}
async function retrieve(url, options) {
	const {id, schemaVersion, validate, timeout, maxBytes, key} = options;
	const storageKey = 'dx.data.v1:' + key;
	const check = envelope => {
		if (envelope?.dataset !== id || envelope.schemaVersion !== schemaVersion || !/^[a-f0-9]{64}$/.test(envelope.version || '') || !Number.isFinite(Date.parse(envelope.generatedAt))) throw new DataUnavailable('Incompatible dataset');
		validate(envelope.data); return envelope;
	};
	const request = async (target, limit = maxBytes) => {
		const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), timeout);
		try {
			const response = await fetch(target, {signal:controller.signal, credentials:'omit', redirect:'error', cache:'default', headers:{Accept:'application/json'}});
			if ([403,410].includes(response.status)) throw new DataUnavailable('Dataset unavailable', true);
			if (!response.ok || !response.headers.get('content-type')?.includes('json') || Number(response.headers.get('content-length')) > limit) throw new DataUnavailable('Dataset request failed');
			let text;
			if (response.body?.getReader) {
				const reader = response.body.getReader(), chunks = []; let size = 0;
				while (true) { const {done,value} = await reader.read(); if (done) break; size += value.byteLength; if (size > limit) { await reader.cancel(); throw new DataUnavailable('Dataset budget exceeded'); } chunks.push(value); }
				const bytes = new Uint8Array(size); let offset = 0; for (const chunk of chunks) { bytes.set(chunk,offset); offset += chunk.byteLength; } text = new TextDecoder('utf-8',{fatal:true}).decode(bytes);
			} else { text = await response.text(); if (new TextEncoder().encode(text).length > limit) throw new DataUnavailable('Dataset budget exceeded'); }
			return {text, value:JSON.parse(text)};
		} finally { clearTimeout(timer); }
	};
	try {
		let manifest;
		for (let attempt = 0; attempt < 2; attempt++) {
			try { manifest = (await request(url,32768)).value; break; }
			catch (error) { if (error.closed || attempt === 1) throw error; await new Promise(resolve => setTimeout(resolve, 250)); }
		}
		if (manifest.available === false) throw new DataUnavailable('Dataset temporarily isolated', true);
		if (manifest.file !== `dataset.${manifest.version}.json` || manifest.dataset !== id || manifest.schemaVersion !== schemaVersion || !/^dataset\.[a-f0-9]{64}\.json$/.test(manifest.file || '') || !/^[a-f0-9]{64}$/.test(manifest.checksum || '')) throw new DataUnavailable('Invalid manifest');
		const {text, value} = await request(new URL(manifest.file, url));
		if (new TextEncoder().encode(text).length !== manifest.bytes || value.version !== manifest.version) throw new DataUnavailable('Dataset integrity mismatch');
		let verified = false;
		if (globalThis.crypto?.subtle) {
			const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
			const checksum = [...new Uint8Array(digest)].map(x => x.toString(16).padStart(2,'0')).join('');
			if (checksum !== manifest.checksum) throw new DataUnavailable('Dataset checksum mismatch');
			verified = true;
		}
		check(value);
		try { if (text.length < 2000000) localStorage.setItem(storageKey, JSON.stringify({at:Date.now(), envelope:value})); } catch (_) { /* Storage is optional. */ }
		return { ...value, stale:false, integrityVerified:verified };
	} catch (error) {
		if (error.closed) { try { localStorage.removeItem(storageKey); } catch (_) {} throw error; }
		try {
			const cached = JSON.parse(localStorage.getItem(storageKey));
			if (cached && Date.now() - cached.at < 86400000 && Date.now() >= cached.at) return {...check(cached.envelope), stale:true, integrityVerified:false};
		} catch (_) { /* Retain the readable static shell. */ }
		throw error;
	}
}
