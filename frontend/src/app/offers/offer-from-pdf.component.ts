import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, Directive, ElementRef, EventEmitter, inject, Output, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { ApiService, ScheduleOffer, SchedulePlacement } from '../services/api.service';
import { OfferDraftStore } from './offer-draft.store';
import { AppBarComponent } from '../steps/app-bar.component';
import { StepTrailComponent } from '../steps/step-trail.component';

@Directive({ selector: '[zoomWheel]', standalone: true })
export class ZoomWheelDirective {
  @Output() zoomWheel = new EventEmitter<WheelEvent>();

  constructor(element: ElementRef<HTMLElement>) {
    element.nativeElement.addEventListener('wheel', (event: WheelEvent) => {
      if (!event.ctrlKey) {
        const page = element.nativeElement.closest('app-offer-from-pdf');
        if (page instanceof HTMLElement) {
          page.scrollTop += event.deltaY;
          page.scrollLeft += event.deltaX;
        }
        event.preventDefault();
        return;
      }
      event.preventDefault();
      this.zoomWheel.emit(event);
    }, { passive: false });
  }
}

interface SheetPreview {
  id: number;
  title: string;
  aspect: number;
}

interface PdfRenderTask {
  promise: Promise<void>;
  cancel: () => void;
}

interface PdfPage {
  getViewport: (params: { scale: number; offsetX?: number; offsetY?: number }) => { width: number; height: number };
  render: (params: { canvasContext: CanvasRenderingContext2D; viewport: object; background?: string }) => PdfRenderTask;
}

interface PdfDocument {
  numPages: number;
  getPage: (pageNumber: number) => Promise<PdfPage>;
  destroy: () => Promise<void>;
}

interface SharpRegion {
  zoom: number;
  left: number;
  top: number;
  width: number;
  height: number;
}

interface TypeGroup {
  key: string;
  board: string;
  device_type: string;
  rating: string;
  quantity: number;
  catalog_number: string | null;
  name: string | null;
  reason: string;
}

@Component({
  selector: 'app-offer-from-pdf',
  standalone: true,
  imports: [StepTrailComponent, AppBarComponent, ZoomWheelDirective],
  templateUrl: './offer-from-pdf.component.html',
  styleUrl: './offer-from-pdf.component.scss',
})
export class OfferFromPdfComponent {
  private api = inject(ApiService);
  private drafts = inject(OfferDraftStore);

  readonly busy = signal(false);
  readonly reading = signal(false);
  readonly readingNote = signal('');
  readonly drawing = signal(false);
  readonly error = signal('');
  readonly offer = signal<ScheduleOffer | null>(null);
  readonly codeHint = signal('');
  readonly files = signal<File[]>([]);
  readonly previews = signal<SheetPreview[]>([]);
  readonly zooms = signal<Record<string, number>>({});
  private loaded = new Map<string, { pdf: PdfDocument; pageNumber: number }>();
  private sharpRegion = new Map<string, SharpRegion>();
  private sharpTimers = new Map<string, number>();
  private sharpGeneration = new Map<string, number>();
  private activeRender = new Map<string, PdfRenderTask>();
  private paintTail = new Map<string, Promise<void>>();
  private pan: { pointerId: number; x: number; y: number; left: number; top: number } | null = null;

  constructor() {
    const onResize = () => {
      for (const page of this.previews()) {
        this.softenSharp(page.id, page.title);
        this.sharpRegion.delete(page.title);
        this.scheduleSharp(page.title);
      }
    };
    window.addEventListener('resize', onResize);
    inject(DestroyRef).onDestroy(() => {
      window.removeEventListener('resize', onResize);
      void this.closeDocuments();
    });
  }

  onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.files.set(input.files ? [...input.files] : []);
    this.previews.set([]);
    this.zooms.set({});
    this.offer.set(null);
    void this.closeDocuments();
    this.codeHint.set('');
    this.error.set('');
  }

  preview(): void {
    if (!this.files().length) {
      this.error.set('Изберете една или повече схеми в PDF.');
      return;
    }
    this.readOffer();
  }

  private readOffer(tiles: File[] = []): void {
    this.busy.set(true);
    this.reading.set(true);
    this.error.set('');
    this.api.previewScheduleOffer(this.files(), tiles).subscribe({
      next: (offer: ScheduleOffer) => {
        this.drafts.save(offer);
        this.offer.set(offer);
        this.busy.set(false);
        this.reading.set(false);
        void this.drawPdfs();
      },
      error: (err: HttpErrorResponse) => {
        if (!tiles.length && err?.error?.needs_tiles) {
          void this.readFromDrawing();
          return;
        }
        this.busy.set(false);
        this.reading.set(false);
        this.readingNote.set('');
        this.error.set(this.failure(err));
      },
    });
  }

  private async readFromDrawing(): Promise<void> {
    try {
      const tiles = await this.pageTiles(this.files());
      if (!tiles.length) {
        throw new Error('empty');
      }
      const groups = this.tileGroups(this.files(), tiles);
      let merged: ScheduleOffer | null = null;
      let lastError = 'Схемата не върна нито един апарат. Проверете дали PDF е електрическата схема.';
      for (let index = 0; index < groups.length; index++) {
        this.readingNote.set(groups.length > 1 ? `Чета част ${index + 1} от ${groups.length}…` : 'Чета схемата…');
        try {
          const part = await firstValueFrom(this.api.previewScheduleOffer(this.files(), groups[index]));
          merged = this.mergeOffers(merged, part);
        } catch (err) {
          const http = err as HttpErrorResponse;
          if (http.status === 422) {
            lastError = this.failure(http);
            continue;
          }
          throw err;
        }
      }
      this.readingNote.set('');
      if (!merged || (merged.boards.length === 0 && merged.placements.length === 0 && merged.unread.length === 0)) {
        this.busy.set(false);
        this.reading.set(false);
        this.error.set(lastError);
        return;
      }
      this.drafts.save(merged);
      this.offer.set(merged);
      this.busy.set(false);
      this.reading.set(false);
      void this.drawPdfs();
    } catch (err) {
      this.busy.set(false);
      this.reading.set(false);
      this.readingNote.set('');
      if (err instanceof HttpErrorResponse) {
        this.error.set(this.failure(err));
        return;
      }
      this.error.set('Схемата не можа да се разреже за четене.');
    }
  }

  private failure(err: HttpErrorResponse): string {
    if (err.status === 0 || err.status === 413) {
      return 'Схемата не се качи. Връзката спря, защото файлът е твърде голям за едно изпращане.';
    }
    const body = err.error as { message?: string } | null;
    if (body && typeof body.message === 'string' && body.message !== '') {
      return body.message;
    }
    return 'Файлът не можа да се прочете.';
  }

  private tileGroups(files: File[], tiles: File[]): File[][] {
    const pdfBytes = files.reduce((sum, file) => sum + file.size, 0);
    const groups: File[][] = [];
    let current: File[] = [];
    let bytes = pdfBytes;
    for (const tile of tiles) {
      if (current.length === 4 || (current.length > 0 && bytes + tile.size > 6_000_000)) {
        groups.push(current);
        current = [];
        bytes = pdfBytes;
      }
      current.push(tile);
      bytes += tile.size;
    }
    if (current.length > 0) {
      groups.push(current);
    }
    return groups;
  }

  private mergeOffers(current: ScheduleOffer | null, next: ScheduleOffer): ScheduleOffer {
    if (!current) {
      return next;
    }
    const boards = current.boards.map((board) => ({
      ...board,
      lines: board.lines.map((line) => ({ ...line })),
    }));
    for (const board of next.boards) {
      const found = boards.find((item) => item.name === board.name);
      if (!found) {
        boards.push({
          ...board,
          lines: board.lines.map((line) => ({ ...line })),
        });
        continue;
      }
      for (const line of board.lines) {
        const same = found.lines.find((item) => item.catalog_number === line.catalog_number);
        if (same) {
          same.quantity += line.quantity;
        } else {
          found.lines.push({ ...line });
        }
      }
    }
    return {
      boards,
      unmatched: [...current.unmatched, ...next.unmatched],
      unread: [...current.unread, ...next.unread],
      note: next.note || current.note,
      placements: [
        ...current.placements,
        ...next.placements.map((mark, index) => ({ ...mark, id: current.placements.length + index + 1 })),
      ],
    };
  }

  private async pageTiles(files: File[]): Promise<File[]> {
    const pdfjs = await import('pdfjs-dist');
    pdfjs.GlobalWorkerOptions.workerSrc = '/pdf.worker.min.js';
    const tiles: File[] = [];
    for (const file of files) {
      const pdf = await pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
      for (let number = 1; number <= pdf.numPages; number++) {
        const page = await pdf.getPage(number);
        const viewport = page.getViewport({ scale: 1.6 });
        const canvas = document.createElement('canvas');
        canvas.width = Math.ceil(viewport.width);
        canvas.height = Math.ceil(viewport.height);
        const context = canvas.getContext('2d');
        if (!context) continue;
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({ canvasContext: context, viewport }).promise;
        const columns = 4;
        const rows = 4;
        const tileW = Math.floor(canvas.width / columns);
        const tileH = Math.floor(canvas.height / rows);
        for (let row = 0; row < rows; row++) {
          for (let column = 0; column < columns; column++) {
            const blob = await this.compactTile(canvas, column * tileW, row * tileH, tileW, tileH);
            if (!blob) continue;
            tiles.push(new File([blob], `${file.name}#${number}-${row}-${column}.jpg`, { type: 'image/jpeg' }));
          }
        }
      }
    }
    return tiles;
  }

  private async compactTile(source: HTMLCanvasElement, sx: number, sy: number, sw: number, sh: number): Promise<Blob | null> {
    let width = sw;
    let height = sh;
    let quality = 0.72;
    for (let attempt = 0; attempt < 4; attempt++) {
      const tile = document.createElement('canvas');
      tile.width = Math.max(1, Math.round(width));
      tile.height = Math.max(1, Math.round(height));
      const context = tile.getContext('2d');
      if (!context) return null;
      context.fillStyle = '#ffffff';
      context.fillRect(0, 0, tile.width, tile.height);
      context.drawImage(source, sx, sy, sw, sh, 0, 0, tile.width, tile.height);
      const blob = await new Promise<Blob | null>((resolve) => tile.toBlob(resolve, 'image/jpeg', quality));
      if (blob && blob.size <= 1_400_000) return blob;
      quality = 0.5;
      width *= 0.75;
      height *= 0.75;
    }
    return null;
  }

  tally(): { read: number; coded: number; open: number } {
    const offer = this.offer();
    if (!offer) return { read: 0, coded: 0, open: 0 };
    const coded = offer.placements.filter((mark) => mark.catalog_number).length;
    const open = offer.placements.length - coded + offer.unread.length;
    return { read: offer.placements.length + offer.unread.length, coded, open };
  }

  zoomOf(title: string): number {
    return this.zooms()[title] ?? 1;
  }

  zoomLabel(title: string): string {
    return `${Math.round(this.zoomOf(title) * 100)}%`;
  }

  zoomBy(title: string, factor: number): void {
    this.setZoom(title, this.zoomOf(title) * factor);
  }

  resetZoom(title: string): void {
    this.setZoom(title, 1);
  }

  onWheel(event: WheelEvent, title: string): void {
    if (!event.ctrlKey) return;
    const viewport = event.currentTarget as HTMLElement;
    const old = this.zoomOf(title);
    const next = this.clampZoom(old * (event.deltaY < 0 ? 1.15 : 1 / 1.15));
    if (next === old) return;
    const rect = viewport.getBoundingClientRect();
    const originX = event.clientX - rect.left + viewport.scrollLeft;
    const originY = event.clientY - rect.top + viewport.scrollTop;
    const ratio = next / old;
    this.setZoom(title, next);
    requestAnimationFrame(() => {
      viewport.scrollLeft = originX * ratio - (event.clientX - rect.left);
      viewport.scrollTop = originY * ratio - (event.clientY - rect.top);
    });
  }

  onPanStart(event: PointerEvent, title: string): void {
    if (event.button !== 0 || this.zoomOf(title) <= 1) return;
    const viewport = event.currentTarget as HTMLElement;
    const bounds = viewport.getBoundingClientRect();
    const x = event.clientX - bounds.left - viewport.clientLeft;
    const y = event.clientY - bounds.top - viewport.clientTop;
    if (x > viewport.clientWidth || y > viewport.clientHeight) return;
    this.pan = {
      pointerId: event.pointerId,
      x: event.clientX,
      y: event.clientY,
      left: viewport.scrollLeft,
      top: viewport.scrollTop,
    };
    viewport.setPointerCapture(event.pointerId);
    viewport.classList.add('panning');
  }

  onPanMove(event: PointerEvent): void {
    if (!this.pan || this.pan.pointerId !== event.pointerId) return;
    const viewport = event.currentTarget as HTMLElement;
    viewport.scrollLeft = this.pan.left - (event.clientX - this.pan.x);
    viewport.scrollTop = this.pan.top - (event.clientY - this.pan.y);
  }

  onPanEnd(event: PointerEvent): void {
    if (!this.pan || this.pan.pointerId !== event.pointerId) return;
    const viewport = event.currentTarget as HTMLElement;
    viewport.classList.remove('panning');
    if (viewport.hasPointerCapture(event.pointerId)) viewport.releasePointerCapture(event.pointerId);
    this.pan = null;
  }

  onSheetScroll(title: string): void {
    const region = this.sharpRegion.get(title);
    const frame = this.frameFor(title);
    if (!frame) return;
    if (region && region.zoom === this.zoomOf(title) && this.regionCovers(region, frame, 32)) return;
    this.scheduleSharp(title);
  }

  private setZoom(title: string, value: number): void {
    const zoom = this.clampZoom(value);
    if (zoom === this.zoomOf(title)) return;
    this.zooms.update((map) => ({ ...map, [title]: zoom }));
    const page = this.previews().find((item) => item.title === title);
    if (page) this.softenSharp(page.id, title);
    this.sharpRegion.delete(title);
    this.scheduleSharp(title);
  }

  private clampZoom(value: number): number {
    return Math.min(5, Math.max(1, Math.round(value * 100) / 100));
  }

  marksOn(title: string): Array<{ key: string; x: number; y: number; ok: boolean; label: string }> {
    const offer = this.offer();
    if (!offer || /,\s*страница\s+(?!1\b)\d+/.test(title)) return [];
    const file = title.replace(/,\s*страница\s+\d+$/, '');
    const marks: Array<{ key: string; x: number; y: number; ok: boolean; label: string }> = [];
    for (const mark of offer.placements) {
      if ((mark.file ?? '') !== file || (mark.x <= 0 && mark.y <= 0)) continue;
      marks.push({
        key: `p${mark.id}`,
        x: mark.x * 100,
        y: mark.y * 100,
        ok: !!mark.catalog_number,
        label: mark.catalog_number ? `${mark.rating} · ${mark.catalog_number}` : `${mark.rating} · без код`,
      });
    }
    offer.unread.forEach((row, index) => {
      const x = row.x ?? 0;
      const y = row.y ?? 0;
      if ((row.file ?? '') !== file || (x <= 0 && y <= 0)) return;
      marks.push({ key: `u${index}`, x: x * 100, y: y * 100, ok: false, label: `${row.text} · без код` });
    });
    return marks;
  }

  groups(): TypeGroup[] {
    const offer = this.offer();
    if (!offer) return [];
    const map = new Map<string, TypeGroup>();
    for (const mark of offer.placements) {
      const key = `${mark.board}|${mark.device_type}|${mark.rating}`;
      const group = map.get(key);
      if (!group) {
        map.set(key, {
          key,
          board: mark.board,
          device_type: mark.device_type,
          rating: mark.rating,
          quantity: 1,
          catalog_number: mark.catalog_number,
          name: mark.name,
          reason: this.reasonFor(offer, mark.board, mark.device_type, mark.rating),
        });
        continue;
      }
      group.quantity++;
      if (group.catalog_number !== mark.catalog_number) {
        group.catalog_number = null;
        group.name = null;
      }
    }
    return [...map.values()].sort((a, b) => {
      if (!a.catalog_number !== !b.catalog_number) return a.catalog_number ? 1 : -1;
      return a.board.localeCompare(b.board, 'bg')
        || a.device_type.localeCompare(b.device_type, 'bg')
        || a.rating.localeCompare(b.rating, 'bg');
    });
  }

  applyGroup(group: TypeGroup, raw: string): void {
    const code = raw.trim();
    const current = this.offer();
    if (!current) return;
    if (code === '') {
      this.writeGroup(current, group, null, null, null);
      this.codeHint.set(`${group.device_type} ${group.rating} е без код.`);
      return;
    }
    this.api.getProducts({ search: code, per_page: 8 }).subscribe({
      next: (page) => {
        const exact = page.data.find((product) => product.catalog_number === code);
        const price = exact?.price === null || exact?.price === undefined ? null : Number(exact.price);
        const name = exact?.name ?? code;
        this.writeGroup(current, group, code, name, price);
        this.codeHint.set(exact
          ? `${name} — записан за ${group.quantity} бр.`
          : 'Кодът е записан за целия тип. Няма го в каталога с цени.');
      },
      error: () => this.codeHint.set('Каталогът не отговори. Кодът не е сменен.'),
    });
  }

  private writeGroup(current: ScheduleOffer, group: TypeGroup, code: string | null, name: string | null, price: number | null): void {
    const placements = current.placements.map((mark) =>
      `${mark.board}|${mark.device_type}|${mark.rating}` === group.key
        ? { ...mark, catalog_number: code, name, unit_price: price }
        : mark
    );
    const next = this.regroup(current, placements);
    this.drafts.save(next);
    this.offer.set(next);
  }

  download(): void {
    const offer = this.offer();
    if (!offer) return;
    this.busy.set(true);
    this.api.downloadScheduleDraft(offer).subscribe({
      next: (blob: Blob) => {
        this.busy.set(false);
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'oferta.xlsx';
        link.click();
        URL.revokeObjectURL(url);
      },
      error: () => {
        this.busy.set(false);
        this.error.set('Excel файлът не можа да се запише.');
      },
    });
  }

  private regroup(current: ScheduleOffer, placements: SchedulePlacement[]): ScheduleOffer {
    const boards: ScheduleOffer['boards'] = [];
    const unmatched: ScheduleOffer['unmatched'] = [];
    for (const mark of placements) {
      if (!mark.catalog_number) {
        const existing = unmatched.find((row) =>
          row.board === mark.board && row.device_type === mark.device_type && row.rating === mark.rating
        );
        if (existing) {
          existing.quantity++;
        } else {
          unmatched.push({
            board: mark.board,
            rating: mark.rating,
            device_type: mark.device_type,
            quantity: 1,
            reason: 'Няма избран код.',
          });
        }
        continue;
      }
      let board = boards.find((item) => item.name === mark.board);
      if (!board) {
        board = { name: mark.board, quantity: 1, lines: [] };
        boards.push(board);
      }
      const line = board.lines.find((item) => item.catalog_number === mark.catalog_number);
      if (line) {
        line.quantity++;
      } else {
        board.lines.push({
          catalog_number: mark.catalog_number,
          name: mark.name ?? mark.catalog_number,
          quantity: 1,
          unit_price: mark.unit_price,
        });
      }
    }

    return { ...current, boards, unmatched, placements };
  }

  private reasonFor(offer: ScheduleOffer, board: string, deviceType: string, rating: string): string {
    return offer.unmatched.find((row) =>
      row.board === board && row.device_type === deviceType && row.rating === rating
    )?.reason ?? '';
  }

  private async drawPdfs(): Promise<void> {
    const files = this.files();
    if (!files.length) return;
    this.drawing.set(true);
    this.previews.set([]);
    await this.closeDocuments();
    try {
      const pdfjs = await import('pdfjs-dist');
      pdfjs.GlobalWorkerOptions.workerSrc = '/pdf.worker.min.js';
      const pages: SheetPreview[] = [];
      let id = 0;
      for (const file of files) {
        const pdf = await pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise as PdfDocument;
        for (let number = 1; number <= pdf.numPages; number++) {
          const title = pdf.numPages > 1 ? `${file.name}, страница ${number}` : file.name;
          const unit = (await pdf.getPage(number)).getViewport({ scale: 1 });
          this.loaded.set(title, { pdf, pageNumber: number });
          pages.push({ id: id++, title, aspect: unit.width / unit.height });
        }
      }
      this.previews.set(pages);
      await new Promise((resolve) => setTimeout(resolve, 0));
      for (const page of pages) {
        await this.runPaint(page.title, () => this.paintBase(page));
        await this.runPaint(page.title, () => this.paintSharp(page.title));
      }
    } catch {
      this.error.set('Схемата не можа да се начертае, но кодовете в таблицата са прочетени.');
    } finally {
      this.drawing.set(false);
    }
  }

  private scheduleSharp(title: string): void {
    const existing = this.sharpTimers.get(title);
    if (existing) window.clearTimeout(existing);
    const generation = (this.sharpGeneration.get(title) ?? 0) + 1;
    this.sharpGeneration.set(title, generation);
    this.sharpTimers.set(title, window.setTimeout(() => {
      this.sharpTimers.delete(title);
      this.cancelRender(title);
      void this.runPaint(title, () => this.paintSharp(title, generation));
    }, 80));
  }

  private runPaint(title: string, job: () => Promise<void>): Promise<void> {
    const previous = this.paintTail.get(title) ?? Promise.resolve();
    const next = previous.then(job, job);
    this.paintTail.set(title, next);
    return next;
  }

  private async paintBase(page: SheetPreview): Promise<void> {
    const entry = this.loaded.get(page.title);
    const canvas = document.querySelector(`canvas[data-base="${page.id}"]`) as HTMLCanvasElement | null;
    if (!entry || !canvas) return;
    const pdfPage = await entry.pdf.getPage(entry.pageNumber);
    const unit = pdfPage.getViewport({ scale: 1 });
    const frame = this.frameFor(page.title);
    const ratio = window.devicePixelRatio || 1;
    const targetWidth = Math.max(1, (frame?.clientWidth || 1600) * ratio);
    const scale = Math.min(targetWidth / unit.width, 8192 / unit.height);
    const fitted = pdfPage.getViewport({ scale });
    canvas.width = Math.max(1, Math.ceil(fitted.width));
    canvas.height = Math.max(1, Math.ceil(fitted.height));
    await this.renderPage(page.title, pdfPage, scale, 0, 0, canvas);
  }

  private async paintSharp(title: string, generation = this.sharpGeneration.get(title) ?? 0): Promise<void> {
    if (generation && this.sharpGeneration.get(title) !== generation) return;
    const entry = this.loaded.get(title);
    const page = this.previews().find((item) => item.title === title);
    const frame = this.frameFor(title);
    const canvas = page ? document.querySelector(`canvas[data-sharp="${page.id}"]`) as HTMLCanvasElement | null : null;
    if (!entry || !page || !frame || !canvas || frame.clientWidth < 2) return;
    const pdfPage = await entry.pdf.getPage(entry.pageNumber);
    if (generation && this.sharpGeneration.get(title) !== generation) return;
    const unit = pdfPage.getViewport({ scale: 1 });
    const ratio = window.devicePixelRatio || 1;
    const zoom = this.zoomOf(title);
    const sheetW = frame.clientWidth * zoom;
    const sheetH = sheetW * (unit.height / unit.width);
    const region = this.visibleRegion(frame, sheetW, sheetH, ratio);
    const scale = (sheetW * ratio) / unit.width;
    const left = Math.round(region.left * ratio) / ratio;
    const top = Math.round(region.top * ratio) / ratio;
    const pixelW = Math.max(1, Math.round(region.width * ratio));
    const pixelH = Math.max(1, Math.round(region.height * ratio));
    const offscreen = document.createElement('canvas');
    offscreen.width = pixelW;
    offscreen.height = pixelH;
    const painted = await this.renderPage(title, pdfPage, scale, -left * ratio, -top * ratio, offscreen);
    if (!painted || !this.loaded.has(title) || (generation && this.sharpGeneration.get(title) !== generation)) return;
    canvas.width = pixelW;
    canvas.height = pixelH;
    canvas.style.left = `${left}px`;
    canvas.style.top = `${top}px`;
    canvas.style.width = `${pixelW / ratio}px`;
    canvas.style.height = `${pixelH / ratio}px`;
    canvas.style.visibility = 'visible';
    canvas.getContext('2d')?.drawImage(offscreen, 0, 0);
    this.sharpRegion.set(title, { zoom, left, top, width: pixelW / ratio, height: pixelH / ratio });
  }

  private async renderPage(
    title: string,
    page: PdfPage,
    scale: number,
    offsetX: number,
    offsetY: number,
    canvas: HTMLCanvasElement,
  ): Promise<boolean> {
    const context = canvas.getContext('2d');
    if (!context || !canvas.width || !canvas.height) return false;
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    const viewport = page.getViewport({ scale, offsetX, offsetY });
    this.cancelRender(title);
    const task = page.render({ canvasContext: context, viewport, background: '#ffffff' });
    this.activeRender.set(title, task);
    try {
      await task.promise;
      return true;
    } catch {
      return false;
    } finally {
      if (this.activeRender.get(title) === task) this.activeRender.delete(title);
    }
  }

  private visibleRegion(
    frame: HTMLElement,
    sheetW: number,
    sheetH: number,
    ratio: number,
  ): { left: number; top: number; width: number; height: number } {
    const fullPixels = sheetW * sheetH * ratio * ratio;
    if (fullPixels <= 8_000_000 && sheetW * ratio <= 16384 && sheetH * ratio <= 16384) {
      return { left: 0, top: 0, width: sheetW, height: sheetH };
    }
    const viewW = frame.clientWidth;
    const viewH = frame.clientHeight;
    let marginX = viewW * 0.75;
    let marginY = viewH * 0.75;
    const widened = (viewW + marginX * 2) * (viewH + marginY * 2) * ratio * ratio;
    if (widened > 8_000_000) {
      marginX = 0;
      marginY = 0;
    }
    const left = Math.max(0, frame.scrollLeft - marginX);
    const top = Math.max(0, frame.scrollTop - marginY);
    return {
      left,
      top,
      width: Math.min(sheetW - left, viewW + marginX * 2),
      height: Math.min(sheetH - top, viewH + marginY * 2),
    };
  }

  private frameFor(title: string): HTMLElement | null {
    const page = this.previews().find((item) => item.title === title);
    if (!page) return null;
    return document.querySelector(`[data-sheet="${page.id}"]`);
  }

  private softenSharp(id: number, title: string): void {
    const canvas = document.querySelector(`canvas[data-sharp="${id}"]`) as HTMLCanvasElement | null;
    if (!canvas) return;
    const region = this.sharpRegion.get(title);
    if (region && region.left <= 1 && region.top <= 1) {
      canvas.style.left = '0';
      canvas.style.top = '0';
      canvas.style.width = '100%';
      canvas.style.height = '100%';
      canvas.style.visibility = 'visible';
      return;
    }
    canvas.style.visibility = 'hidden';
  }

  private regionCovers(region: SharpRegion, frame: HTMLElement, pad: number): boolean {
    const right = frame.scrollLeft + frame.clientWidth;
    const bottom = frame.scrollTop + frame.clientHeight;
    return frame.scrollLeft >= region.left + pad
      && frame.scrollTop >= region.top + pad
      && right <= region.left + region.width - pad
      && bottom <= region.top + region.height - pad;
  }

  private cancelRender(title: string): void {
    const task = this.activeRender.get(title);
    if (!task) return;
    this.activeRender.delete(title);
    try {
      task.cancel();
    } catch {
      // The render already finished.
    }
  }

  private async closeDocuments(): Promise<void> {
    for (const timer of this.sharpTimers.values()) window.clearTimeout(timer);
    this.sharpTimers.clear();
    this.sharpGeneration.clear();
    for (const title of [...this.activeRender.keys()]) this.cancelRender(title);
    const pdfs = [...new Set([...this.loaded.values()].map((entry) => entry.pdf))];
    this.loaded.clear();
    this.sharpRegion.clear();
    await Promise.all(pdfs.map((pdf) => pdf.destroy().catch(() => undefined)));
  }
}
