/* Generates the checked-in, dependency-free PNG assets declared in app.json. */
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');

const size = 48;
const inactive = [107, 114, 128];
const active = [0, 122, 255];
const names = ['recipes', 'calendar', 'inventory', 'shopping', 'tasks'];

function crc32(buffer) {
  let crc = 0xffffffff;
  for (const byte of buffer) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit += 1) crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0);
  }
  return (crc ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const header = Buffer.alloc(8);
  header.writeUInt32BE(data.length, 0);
  header.write(type, 4, 4, 'ascii');
  const checksum = Buffer.alloc(4);
  checksum.writeUInt32BE(crc32(Buffer.concat([Buffer.from(type), data])), 0);
  return Buffer.concat([header, data, checksum]);
}

function png(pixels) {
  const scanlines = Buffer.alloc((size * 3 + 1) * size);
  for (let y = 0; y < size; y += 1) {
    const row = y * (size * 3 + 1);
    scanlines[row] = 0;
    pixels.copy(scanlines, row + 1, y * size * 3, (y + 1) * size * 3);
  }
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0); ihdr.writeUInt32BE(size, 4);
  ihdr[8] = 8; ihdr[9] = 2;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(scanlines)), chunk('IEND', Buffer.alloc(0))]);
}

function pixelsFor(name, color) {
  const pixels = Buffer.alloc(size * size * 3, 255);
  const dot = (x, y) => { if (x >= 0 && x < size && y >= 0 && y < size) color.forEach((value, channel) => { pixels[(y * size + x) * 3 + channel] = value; }); };
  const line = (x1, y1, x2, y2) => {
    const dx = Math.abs(x2 - x1), dy = -Math.abs(y2 - y1), sx = x1 < x2 ? 1 : -1, sy = y1 < y2 ? 1 : -1;
    let err = dx + dy, x = x1, y = y1;
    while (true) { for (let ox = -1; ox <= 1; ox += 1) for (let oy = -1; oy <= 1; oy += 1) dot(x + ox, y + oy); if (x === x2 && y === y2) break; const twice = 2 * err; if (twice >= dy) { err += dy; x += sx; } if (twice <= dx) { err += dx; y += sy; } }
  };
  const rect = (x, y, width, height) => { line(x, y, x + width, y); line(x, y, x, y + height); line(x + width, y, x + width, y + height); line(x, y + height, x + width, y + height); };
  if (name === 'recipes') { rect(11, 7, 26, 34); line(17, 14, 31, 14); line(17, 22, 31, 22); line(17, 30, 28, 30); }
  if (name === 'calendar') { rect(8, 11, 32, 28); line(8, 19, 40, 19); line(16, 7, 16, 15); line(32, 7, 32, 15); line(16, 27, 17, 27); line(24, 27, 25, 27); line(32, 27, 33, 27); }
  if (name === 'inventory') { rect(13, 12, 22, 29); line(17, 7, 31, 7); line(17, 7, 17, 12); line(31, 7, 31, 12); line(17, 23, 31, 23); }
  if (name === 'shopping') { line(9, 11, 15, 11); line(14, 11, 18, 31); line(18, 17, 38, 17); line(18, 31, 35, 31); line(22, 38, 22, 38); line(34, 38, 34, 38); }
  if (name === 'tasks') { rect(9, 9, 30, 30); line(14, 24, 20, 30); line(20, 30, 34, 16); }
  return pixels;
}

const output = path.resolve(__dirname, '..', 'assets', 'tab');
fs.mkdirSync(output, { recursive: true });
for (const name of names) {
  fs.writeFileSync(path.join(output, `${name}.png`), png(pixelsFor(name, inactive)));
  fs.writeFileSync(path.join(output, `${name}-active.png`), png(pixelsFor(name, active)));
}
