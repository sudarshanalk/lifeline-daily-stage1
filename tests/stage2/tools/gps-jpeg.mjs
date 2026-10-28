// A real JPEG carrying EXIF location data (GPS latitude and a GPSMapDatum marker), for the diary photo checks
// (TC-FR2-37-AS-07), and a reader that finds a GPS block in any JPEG's EXIF segment.

// 16 x 16 baseline JPEG made with PHP GD; no EXIF of its own.
const BASE = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgAEAAQAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8AyKKKK+RP0g//2Q==';
export const GPS_MARK = 'LLDGPSMARK';

function entry(tag, type, count, value) {
  const b = Buffer.alloc(12);
  b.writeUInt16LE(tag, 0);
  b.writeUInt16LE(type, 2);
  b.writeUInt32LE(count, 4);
  if (Buffer.isBuffer(value)) value.copy(b, 8); else b.writeUInt32LE(value, 8);
  return b;
}

/** The base JPEG with an APP1 Exif segment: IFD0 -> GPS IFD (latitude ref, latitude, map datum). */
export function gpsJpeg() {
  const gpsAt = 8 + 2 + 12 + 4;
  const dataAt = gpsAt + 2 + 3 * 12 + 4;
  const lat = Buffer.alloc(24);
  [[51, 1], [30, 1], [0, 1]].forEach(([n, d], i) => { lat.writeUInt32LE(n, i * 8); lat.writeUInt32LE(d, i * 8 + 4); });
  const datum = Buffer.from(GPS_MARK + '\0', 'latin1');
  const tiff = Buffer.concat([
    Buffer.from('II*\0', 'latin1'), Buffer.from([8, 0, 0, 0]),
    Buffer.from([1, 0]), entry(0x8825, 4, 1, gpsAt), Buffer.alloc(4),
    Buffer.from([3, 0]),
    entry(0x0001, 2, 2, Buffer.from('N\0\0\0', 'latin1')),
    entry(0x0002, 5, 3, dataAt),
    entry(0x0012, 2, datum.length, dataAt + lat.length),
    Buffer.alloc(4),
    lat, datum,
  ]);
  const body = Buffer.concat([Buffer.from('Exif\0\0', 'latin1'), tiff]);
  const head = Buffer.from([0xff, 0xe1, 0, 0]);
  head.writeUInt16BE(body.length + 2, 2);
  const jpeg = Buffer.from(BASE, 'base64');
  return Buffer.concat([jpeg.subarray(0, 2), head, body, jpeg.subarray(2)]);
}

/** True when a JPEG's EXIF IFD0 points at a GPS block. */
export function hasGps(buf) {
  let at = 2;
  while (at + 4 <= buf.length && buf[at] === 0xff) {
    const marker = buf[at + 1];
    if (marker === 0xda || marker === 0xd9) break;
    const length = buf.readUInt16BE(at + 2);
    const seg = buf.subarray(at + 4, at + 2 + length);
    if (marker === 0xe1 && seg.subarray(0, 6).toString('latin1') === 'Exif\0\0') {
      const t = seg.subarray(6);
      const le = t.subarray(0, 2).toString('latin1') === 'II';
      const u16 = (o) => (le ? t.readUInt16LE(o) : t.readUInt16BE(o));
      const u32 = (o) => (le ? t.readUInt32LE(o) : t.readUInt32BE(o));
      const ifd = u32(4);
      for (let i = 0; i < u16(ifd); i += 1) if (u16(ifd + 2 + i * 12) === 0x8825) return true;
    }
    at += 2 + length;
  }
  return false;
}
