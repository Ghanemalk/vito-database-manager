// Files and the clipboard: downloads, copying, and reading a chosen file.

import { h, put } from '../ui/dom.js';
import { toast } from '../ui/toasts.js';

/** Saves data (text or a Blob) as a file. */
export function download(data, filename, mime) {
  const blob = data instanceof Blob ? data : new Blob([data], { type: mime || 'application/octet-stream' });
  const url = URL.createObjectURL(blob);
  const a = h('a', { href: url, download: filename });
  put(document.body, a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 30000);
}

export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    toast('Copied to the clipboard');
  } catch {
    toast('The browser did not allow copying.', 'err');
  }
}

export const base64ToBytes = (b64) => Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));

/** Hexadecimal digits for the first max bytes. */
export const bytesToHex = (u8, max = Infinity) =>
  Array.from(u8.subarray(0, Math.min(u8.length, max)), (b) => b.toString(16).padStart(2, '0')).join('');

/** The contents of a chosen file, base64-encoded. */
export function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(String(r.result).replace(/^data:[^,]*,/, ''));
    r.onerror = () => reject(new Error('The file could not be read.'));
    r.readAsDataURL(file);
  });
}
