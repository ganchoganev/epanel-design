import { Injectable, inject } from '@angular/core';
import { catchError, firstValueFrom, of } from 'rxjs';
import { ApiService, ProductFace, ScheduleOffer } from '../services/api.service';
import { OfferDraftStore } from './offer-draft.store';
import {
  EnclosureOption,
  deviceModules,
  loadEnclosureChoices,
  packDevices,
  railsOrFallback,
  suggestEnclosure,
} from './board-fit';
import { canvasSheet, downloadSheets, encodeCanvasJpeg, loadImage, PdfSheet, tableCanvas } from './view-pdf';

@Injectable({ providedIn: 'root' })
export class ProjectPrintService {
  private api = inject(ApiService);
  private drafts = inject(OfferDraftStore);

  async download(): Promise<void> {
    const offer = this.drafts.load();
    if (!offer?.boards.length) {
      throw new Error('Няма прочетена оферта.');
    }

    const pages = offer.boards.map((board) => tableCanvas(`Оферта · ${board.name}`, [
      ['Код', 'Наименование', 'Брой', 'Цена'],
      ...board.lines.map((line) => [
        line.catalog_number,
        line.name,
        String(line.quantity),
        line.unit_price == null ? '' : `${line.unit_price} EUR`,
      ]),
    ]));

    const catalog = await firstValueFrom(
      this.api.enclosures().pipe(catchError(() => of({ enclosures: [] as EnclosureOption[] }))),
    );
    const codes = [...new Set(offer.boards.flatMap((board) => board.lines.map((line) => line.catalog_number)))];
    const choices = loadEnclosureChoices();
    const faces = new Map<string, ProductFace | null>();
    await Promise.all(codes.map(async (code) => {
      faces.set(code, await firstValueFrom(this.api.productFace(code).pipe(catchError(() => of(null)))));
    }));
    const enclosureCodes = offer.boards
      .map((board) => this.enclosureFor(board, catalog.enclosures, choices, faces)?.catalog_number)
      .filter((code): code is string => !!code);
    for (const code of enclosureCodes) {
      if (!faces.has(code)) {
        faces.set(code, await firstValueFrom(this.api.productFace(code).pipe(catchError(() => of(null)))));
      }
    }
    const photos = new Map<string, CatalogShot | null>();
    await Promise.all([...new Set([...codes, ...enclosureCodes])].map(async (code) => {
      const blob = await firstValueFrom(this.api.productPhoto(code).pipe(catchError(() => of(null))));
      photos.set(code, blob ? await this.shotFromBlob(blob) : null);
    }));

    const urls: string[] = [];
    const faceImages = new Map<string, HTMLImageElement | null>();
    for (const [code, face] of faces) {
      if (!face?.svg) {
        faceImages.set(code, null);
        continue;
      }
      const width = Math.max(8, Math.round(face.width_mm * PX_PER_MM));
      const height = Math.max(8, Math.round(face.height_mm * PX_PER_MM));
      const svg = face.svg.replace('<svg ', `<svg width="${width}" height="${height}" `);
      const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
      urls.push(url);
      faceImages.set(code, await loadImage(url));
    }

    const sheets: PdfSheet[] = await Promise.all(pages.map((page) => canvasSheet(page)));
    for (const board of offer.boards) {
      const option = this.enclosureFor(board, catalog.enclosures, choices, faces);
      if (!option) continue;
      await new Promise((resolve) => setTimeout(resolve, 0));
      const shell = faces.get(option.catalog_number);
      sheets.push(await canvasSheet(this.draw2d(board, option, shell ?? null, faces, faceImages)));
      sheets.push(...await this.photoSheets(board, option, photos.get(option.catalog_number) ?? null, photos, faces));
    }

    for (const url of urls) URL.revokeObjectURL(url);
    await downloadSheets(sheets, 'proekt.pdf');
  }

  private enclosureFor(
    board: ScheduleOffer['boards'][number],
    options: EnclosureOption[],
    choices: Record<string, string>,
    faces: Map<string, ProductFace | null>,
  ): EnclosureOption | null {
    const stored = options.find((option) => option.catalog_number === choices[board.name]);
    if (stored) return stored;
    let total = 0;
    let widest = 1;
    for (const line of board.lines) {
      const face = faces.get(line.catalog_number);
      const modules = face ? deviceModules(face.width_mm) : 1;
      total += modules * line.quantity;
      widest = Math.max(widest, modules);
    }
    return suggestEnclosure(options, total, widest);
  }

  private draw2d(
    board: ScheduleOffer['boards'][number],
    option: EnclosureOption,
    shell: ProductFace | null,
    faces: Map<string, ProductFace | null>,
    images: Map<string, HTMLImageElement | null>,
  ): HTMLCanvasElement {
    const scale = PX_PER_MM;
    const header = 56;
    const widthMm = shell?.width_mm || option.modules_per_row * 18 + 80;
    const heightMm = shell?.height_mm || option.rows * 140;
    const canvas = document.createElement('canvas');
    canvas.width = Math.ceil(widthMm * scale) + 24;
    canvas.height = Math.ceil(heightMm * scale) + header + 12;
    const ctx = canvas.getContext('2d');
    if (!ctx) return canvas;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.fillStyle = '#263238';
    ctx.font = 'bold 32px Roboto, sans-serif';
    ctx.fillText(`2D · ${board.name} · ${option.name}`, 12, 28);

    const shellImage = images.get(option.catalog_number);
    if (shellImage) ctx.drawImage(shellImage, 12, header, widthMm * scale, heightMm * scale);

    const rails = railsOrFallback(shell?.rails ?? [], option.rows, option.modules_per_row, widthMm, heightMm);
    const packed = packDevices(
      board.lines,
      (code) => {
        const face = faces.get(code);
        return face ? deviceModules(face.width_mm) : 1;
      },
      (code) => faces.get(code)?.height_mm ?? 90,
      option.rows,
      option.modules_per_row,
    );
    packed.placed.forEach((device) => {
      const rail = rails[device.row];
      const image = images.get(device.code);
      const face = faces.get(device.code);
      if (!rail || !image || !face) return;
      ctx.drawImage(
        image,
        12 + (rail.x + device.xMm) * scale,
        header + (rail.y - face.height_mm / 2) * scale,
        device.modules * 18 * scale,
        face.height_mm * scale,
      );
    });
    return canvas;
  }

  /**
   * Each board row stays one PDF row across the full page width.
   * The enclosure photo sits on its own row so the apparatus is as large as the page allows.
   */
  private async photoSheets(
    board: ScheduleOffer['boards'][number],
    option: EnclosureOption,
    enclosure: CatalogShot | null,
    photos: Map<string, CatalogShot | null>,
    faces: Map<string, ProductFace | null>,
  ): Promise<PdfSheet[]> {
    const packed = packDevices(
      board.lines,
      (code) => {
        const face = faces.get(code);
        return face ? deviceModules(face.width_mm) : 1;
      },
      () => 90,
      option.rows,
      option.modules_per_row,
    );
    const pageW = 841.89;
    const pageH = 595.28;
    const margin = 14;
    const titleH = 24;
    const contentTop = pageH - margin - titleH - 4;
    const contentH = contentTop - margin;
    const rowGap = 6;
    const rows = Math.max(1, option.rows);
    const innerW = pageW - margin * 2;
    const modulePt = innerW / option.modules_per_row;
    const enclosureGap = enclosure ? 8 : 0;
    const rowsAtFull = rows * modulePt + (rows - 1) * rowGap;
    let enclosureH = 0;
    if (enclosure) {
      const leftover = contentH - rowsAtFull - enclosureGap;
      enclosureH = Math.min(240, Math.max(56, leftover));
      if (rowsAtFull + enclosureH + enclosureGap > contentH) {
        enclosureH = Math.max(40, contentH - rowsAtFull - enclosureGap);
      }
    }
    const rowH = (contentH - enclosureH - enclosureGap - (rows - 1) * rowGap) / rows;
    const deviceTop = contentTop - enclosureH - enclosureGap;
    const images: PdfSheet['images'] = [];
    const fills: PdfSheet['fills'] = [];
    for (let row = 0; row < rows; row++) {
      fills.push({
        x: margin,
        y: deviceTop - (row + 1) * rowH - row * rowGap,
        width: innerW,
        height: rowH,
        r: 0.969,
        g: 0.973,
        b: 0.973,
      });
    }
    for (const device of packed.placed) {
      const photo = photos.get(device.code);
      if (!photo || device.row >= rows) continue;
      const slotW = device.modules * modulePt;
      const slotX = margin + (device.xMm / 18) * modulePt;
      const slotY = deviceTop - (device.row + 1) * rowH - device.row * rowGap;
      const fitted = this.fitShot(photo, Math.max(1, slotW - 2), Math.max(1, rowH - 2));
      images.push({
        data: photo.jpeg,
        width: photo.width,
        height: photo.height,
        x: slotX + (slotW - fitted.drawWidth) / 2,
        y: slotY + (rowH - fitted.drawHeight) / 2,
        drawWidth: fitted.drawWidth,
        drawHeight: fitted.drawHeight,
      });
    }
    const title = await this.titleImage(`Снимка · ${board.name} · ${option.name}`);
    images.unshift({
      ...title,
      x: margin,
      y: pageH - margin - titleH,
      drawWidth: innerW,
      drawHeight: titleH,
    });
    if (enclosure && enclosureH > 0) {
      const fitted = this.fitShot(enclosure, Math.min(innerW, 420), enclosureH);
      images.splice(1, 0, {
        data: enclosure.jpeg,
        width: enclosure.width,
        height: enclosure.height,
        x: margin + (innerW - fitted.drawWidth) / 2,
        y: deviceTop + enclosureGap + (enclosureH - fitted.drawHeight) / 2,
        drawWidth: fitted.drawWidth,
        drawHeight: fitted.drawHeight,
      });
    }
    return [{ mediaWidth: pageW, mediaHeight: pageH, images, fills }];
  }

  private fitShot(shot: CatalogShot, maxWidth: number, maxHeight: number): { drawWidth: number; drawHeight: number } {
    const fit = Math.min(maxWidth / shot.width, maxHeight / shot.height);
    return { drawWidth: shot.width * fit, drawHeight: shot.height * fit };
  }

  private async titleImage(text: string): Promise<JpegBytes> {
    const canvas = document.createElement('canvas');
    canvas.width = 2000;
    canvas.height = 80;
    const ctx = canvas.getContext('2d');
    if (!ctx) return { data: new Uint8Array(), width: 1, height: 1 };
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#263238';
    ctx.font = 'bold 44px Roboto, sans-serif';
    ctx.fillText(text, 8, 54);
    const jpeg = await encodeCanvasJpeg(canvas);
    return { data: jpeg.data, width: jpeg.width, height: jpeg.height };
  }

  private async shotFromBlob(blob: Blob): Promise<CatalogShot | null> {
    const bytes = new Uint8Array(await blob.arrayBuffer());
    const url = URL.createObjectURL(new Blob([bytes], { type: blob.type || 'image/jpeg' }));
    const image = await loadImage(url);
    URL.revokeObjectURL(url);
    if (!image) return null;
    if (bytes.length > 2 && bytes[0] === 0xff && bytes[1] === 0xd8) {
      return { jpeg: bytes, width: image.naturalWidth, height: image.naturalHeight };
    }
    const canvas = document.createElement('canvas');
    canvas.width = image.naturalWidth;
    canvas.height = image.naturalHeight;
    canvas.getContext('2d')?.drawImage(image, 0, 0);
    const jpeg = await encodeCanvasJpeg(canvas);
    return { jpeg: jpeg.data, width: jpeg.width, height: jpeg.height };
  }
}

interface CatalogShot {
  jpeg: Uint8Array;
  width: number;
  height: number;
}

interface JpegBytes {
  data: Uint8Array;
  width: number;
  height: number;
}

const PX_PER_MM = 4;
