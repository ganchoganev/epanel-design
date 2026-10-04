/** One JPEG page, fitted on A4. Cyrillic is drawn into the picture, not as PDF text. */

export async function downloadDataUrl(dataUrl: string, filename: string): Promise<void> {
  const image = await loadImage(dataUrl);
  if (!image) return;
  const canvas = document.createElement('canvas');
  canvas.width = image.naturalWidth;
  canvas.height = image.naturalHeight;
  canvas.getContext('2d')?.drawImage(image, 0, 0);
  await downloadCanvases([canvas], filename);
}

/** A catalog JPEG placed on the page. Coordinates are PDF points, origin bottom-left. */
export interface PdfImage {
  data: Uint8Array;
  width: number;
  height: number;
  x: number;
  y: number;
  drawWidth: number;
  drawHeight: number;
}

export interface PdfFill {
  x: number;
  y: number;
  width: number;
  height: number;
  r: number;
  g: number;
  b: number;
}

export interface PdfSheet {
  mediaWidth: number;
  mediaHeight: number;
  images: PdfImage[];
  fills?: PdfFill[];
}

const JPEG_QUALITY = 0.95;

export async function canvasSheet(canvas: HTMLCanvasElement): Promise<PdfSheet> {
  const jpeg = await encodeCanvasJpeg(canvas);
  const landscape = jpeg.width > jpeg.height;
  const mediaWidth = landscape ? 841.89 : 595.28;
  const mediaHeight = landscape ? 595.28 : 841.89;
  const scale = Math.min((mediaWidth - 36) / jpeg.width, (mediaHeight - 36) / jpeg.height);
  const drawWidth = jpeg.width * scale;
  const drawHeight = jpeg.height * scale;
  return {
    mediaWidth,
    mediaHeight,
    images: [{
      data: jpeg.data,
      width: jpeg.width,
      height: jpeg.height,
      x: (mediaWidth - drawWidth) / 2,
      y: (mediaHeight - drawHeight) / 2,
      drawWidth,
      drawHeight,
    }],
  };
}

export async function downloadSheets(sheets: PdfSheet[], filename: string): Promise<void> {
  const usable = sheets.filter((sheet) => sheet.images.length > 0);
  if (!usable.length) return;
  const bytes = jpegPdf(usable);
  const blob = new Blob([bytes], { type: 'application/pdf' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export async function downloadCanvases(pages: HTMLCanvasElement[], filename: string): Promise<void> {
  const sheets = await Promise.all(pages.filter((page) => page.width > 0).map(canvasSheet));
  await downloadSheets(sheets, filename);
}

export function captureElement(element: HTMLElement, title: string): HTMLCanvasElement {
  const box = element.getBoundingClientRect();
  const scale = 2;
  const header = 36;
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(box.width * scale));
  canvas.height = Math.max(1, Math.round((box.height + header) * scale));
  const ctx = canvas.getContext('2d');
  if (!ctx) return canvas;
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.fillStyle = '#263238';
  ctx.font = `${16 * scale}px Roboto, sans-serif`;
  ctx.fillText(title, 12 * scale, 24 * scale);
  ctx.scale(scale, scale);
  for (const image of element.querySelectorAll('img')) {
    if (!image.complete || image.naturalWidth === 0) continue;
    const rect = image.getBoundingClientRect();
    ctx.drawImage(image, rect.left - box.left, header + rect.top - box.top, rect.width, rect.height);
  }
  return canvas;
}

export function tableCanvas(title: string, rows: string[][]): HTMLCanvasElement {
  const width = 900;
  const rowHeight = 28;
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = 64 + Math.max(1, rows.length) * rowHeight;
  const ctx = canvas.getContext('2d');
  if (!ctx) return canvas;
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.fillStyle = '#263238';
  ctx.font = 'bold 22px Roboto, sans-serif';
  ctx.fillText(title, 24, 36);
  ctx.font = '14px Roboto, sans-serif';
  rows.forEach((row, index) => {
    const y = 64 + index * rowHeight;
    if (index % 2 === 0) {
      ctx.fillStyle = '#f5f7f8';
      ctx.fillRect(16, y, width - 32, rowHeight);
    }
    ctx.fillStyle = '#263238';
    ctx.fillText(row[0] ?? '', 24, y + 19);
    ctx.fillText(row[1] ?? '', 150, y + 19);
    ctx.fillText(row[2] ?? '', 680, y + 19);
    ctx.fillText(row[3] ?? '', 760, y + 19);
  });
  return canvas;
}

export async function loadImage(url: string): Promise<HTMLImageElement | null> {
  try {
    return await new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = () => reject(new Error('image'));
      image.src = url;
    });
  } catch {
    return null;
  }
}

interface JpegPage {
  data: Uint8Array;
  width: number;
  height: number;
}

export async function encodeCanvasJpeg(canvas: HTMLCanvasElement): Promise<JpegPage> {
  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY));
  if (!blob) throw new Error('jpeg');
  return {
    data: new Uint8Array(await blob.arrayBuffer()),
    width: canvas.width,
    height: canvas.height,
  };
}

function sheetContent(page: PdfSheet): string {
  const lines: string[] = [];
  for (const fill of page.fills ?? []) {
    lines.push(
      `${fill.r.toFixed(3)} ${fill.g.toFixed(3)} ${fill.b.toFixed(3)} rg ${fill.x.toFixed(2)} ${fill.y.toFixed(2)} ${fill.width.toFixed(2)} ${fill.height.toFixed(2)} re f`,
    );
  }
  page.images.forEach((image, index) => {
    lines.push(
      `q ${image.drawWidth.toFixed(2)} 0 0 ${image.drawHeight.toFixed(2)} ${image.x.toFixed(2)} ${image.y.toFixed(2)} cm /Im${index} Do Q`,
    );
  });
  return lines.join('\n');
}

function jpegPdf(pages: PdfSheet[]): Uint8Array {
  const parts: Uint8Array[] = [];
  const offsets: number[] = [];
  let cursor = 0;
  const add = (bytes: Uint8Array) => {
    parts.push(bytes);
    cursor += bytes.length;
  };
  const text = (value: string) => add(new TextEncoder().encode(value));

  text('%PDF-1.4\n');
  const pageIds: number[] = [];
  const contentIds: number[] = [];
  const imageIds: number[][] = [];
  let next = 3;
  for (const page of pages) {
    pageIds.push(next);
    contentIds.push(next + 1);
    const ids: number[] = [];
    for (let index = 0; index < page.images.length; index++) ids.push(next + 2 + index);
    imageIds.push(ids);
    next += 2 + page.images.length;
  }

  offsets[1] = cursor;
  text(`1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n`);
  offsets[2] = cursor;
  text(`2 0 obj\n<< /Type /Pages /Count ${pages.length} /Kids [${pageIds.map((id) => `${id} 0 R`).join(' ')}] >>\nendobj\n`);

  pages.forEach((page, index) => {
    const pageId = pageIds[index];
    const contentId = contentIds[index];
    const images = imageIds[index];
    const xobjects = images.map((id, imageIndex) => `/Im${imageIndex} ${id} 0 R`).join(' ');
    const content = sheetContent(page);
    offsets[pageId] = cursor;
    text(
      `${pageId} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${page.mediaWidth} ${page.mediaHeight}] /Contents ${contentId} 0 R /Resources << /XObject << ${xobjects} >> >> >>\nendobj\n`,
    );
    offsets[contentId] = cursor;
    text(`${contentId} 0 obj\n<< /Length ${content.length} >>\nstream\n${content}\nendstream\nendobj\n`);
    page.images.forEach((image, imageIndex) => {
      const id = images[imageIndex];
      offsets[id] = cursor;
      text(
        `${id} 0 obj\n<< /Type /XObject /Subtype /Image /Width ${image.width} /Height ${image.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Interpolate true /Filter /DCTDecode /Length ${image.data.length} >>\nstream\n`,
      );
      add(image.data);
      text('\nendstream\nendobj\n');
    });
  });

  const xref = cursor;
  const size = next;
  let xrefBody = `xref\n0 ${size}\n0000000000 65535 f \n`;
  for (let id = 1; id < size; id++) {
    xrefBody += `${String(offsets[id] ?? 0).padStart(10, '0')} 00000 n \n`;
  }
  text(xrefBody);
  text(`trailer\n<< /Size ${size} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`);

  const total = parts.reduce((sum, part) => sum + part.length, 0);
  const out = new Uint8Array(total);
  let offset = 0;
  for (const part of parts) {
    out.set(part, offset);
    offset += part.length;
  }
  return out;
}
