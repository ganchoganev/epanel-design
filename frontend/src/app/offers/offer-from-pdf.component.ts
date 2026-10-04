import { HttpErrorResponse } from '@angular/common/http';
import { Component, ElementRef, inject, OnDestroy, signal, ViewChild } from '@angular/core';
import { ApiService, ScheduleOffer, SchedulePlacement } from '../services/api.service';
import { OfferDraftStore } from './offer-draft.store';
import { StepTrailComponent } from '../steps/step-trail.component';
interface TypeGroup {
  key: string;
  board: string;
  device_type: string;
  rating: string;
  quantity: number;
  catalog_number: string | null;
  name: string | null;
}

@Component({
  selector: 'app-offer-from-pdf',
  standalone: true,
  imports: [StepTrailComponent],
  templateUrl: './offer-from-pdf.component.html',
  styleUrl: './offer-from-pdf.component.scss',
})
export class OfferFromPdfComponent implements OnDestroy {
  private api = inject(ApiService);
  private drafts = inject(OfferDraftStore);

  @ViewChild('sheet') canvas?: ElementRef<HTMLCanvasElement>;
  @ViewChild('viewport') viewport?: ElementRef<HTMLDivElement>;

  readonly busy = signal(false);
  readonly drawing = signal(false);
  readonly error = signal('');
  readonly offer = signal<ScheduleOffer | null>(null);
  readonly selectedKey = signal<string | null>(null);
  readonly codeHint = signal('');
  readonly sheetWidth = signal(0);
  readonly sheetHeight = signal(0);
  readonly viewScale = signal(1);
  readonly panX = signal(0);
  readonly panY = signal(0);
  readonly fitScale = signal(1);
  file: File | null = null;
  private drag: { x: number; y: number; panX: number; panY: number } | null = null;
  private wheelTarget: HTMLElement | null = null;
  private readonly onWheel = (event: WheelEvent) => {
    event.preventDefault();
    const box = this.viewport?.nativeElement;
    if (!box) return;
    const rect = box.getBoundingClientRect();
    this.zoomBy(event.deltaY < 0 ? 1.12 : 1 / 1.12, event.clientX - rect.left, event.clientY - rect.top);
  };

  onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.file = input.files?.[0] ?? null;
    this.offer.set(null);
    this.selectedKey.set(null);
    this.codeHint.set('');
    this.error.set('');
  }

  preview(): void {
    if (!this.file) {
      this.error.set('Изберете PDF.');
      return;
    }
    this.busy.set(true);
    this.error.set('');
    this.api.previewScheduleOffer(this.file).subscribe({
      next: (offer: ScheduleOffer) => {
        this.drafts.save(offer);
        this.offer.set(offer);
        this.busy.set(false);
        setTimeout(() => void this.drawPdf(), 0);
      },
      error: (err: HttpErrorResponse) => {
        this.busy.set(false);
        this.error.set(err?.error?.message || 'Файлът не можа да се прочете.');
      },
    });
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

  ngOnDestroy(): void {
    this.wheelTarget?.removeEventListener('wheel', this.onWheel);
  }

  zoomPercent(): number {
    const fit = this.fitScale();
    if (fit <= 0) return 100;
    return Math.round((this.viewScale() / fit) * 100);
  }

  zoomBy(factor: number, originX?: number, originY?: number): void {
    const box = this.viewport?.nativeElement;
    if (!box || this.sheetWidth() <= 0) return;
    const ox = originX ?? box.clientWidth / 2;
    const oy = originY ?? box.clientHeight / 2;
    const prev = this.viewScale();
    const next = Math.min(6, Math.max(this.fitScale() * 0.8, prev * factor));
    const paperX = (ox - this.panX()) / prev;
    const paperY = (oy - this.panY()) / prev;
    this.viewScale.set(next);
    this.panX.set(ox - paperX * next);
    this.panY.set(oy - paperY * next);
  }

  fitSheet(): void {
    const box = this.viewport?.nativeElement;
    const width = this.sheetWidth();
    const height = this.sheetHeight();
    if (!box || width <= 0 || height <= 0) return;
    const scale = Math.min(box.clientWidth / width, box.clientHeight / height);
    this.fitScale.set(scale);
    this.viewScale.set(scale);
    this.panX.set((box.clientWidth - width * scale) / 2);
    this.panY.set((box.clientHeight - height * scale) / 2);
  }

  selectGroup(key: string): void {
    this.selectedKey.set(key);
    queueMicrotask(() => this.focusMarks());
  }

  focusMarks(): void {
    const marks = this.selectedMarks();
    const box = this.viewport?.nativeElement;
    const width = this.sheetWidth();
    const height = this.sheetHeight();
    if (!marks.length || !box || width <= 0 || height <= 0) return;
    const xs = marks.map((mark) => mark.x * width);
    const ys = marks.map((mark) => mark.y * height);
    const minX = Math.min(...xs);
    const maxX = Math.max(...xs);
    const minY = Math.min(...ys);
    const maxY = Math.max(...ys);
    const pad = 70;
    const spanX = Math.max(maxX - minX, 48) + pad * 2;
    const spanY = Math.max(maxY - minY, 48) + pad * 2;
    const scale = Math.min(6, Math.max(this.fitScale(), Math.min(box.clientWidth / spanX, box.clientHeight / spanY)));
    this.viewScale.set(scale);
    this.panX.set(box.clientWidth / 2 - ((minX + maxX) / 2) * scale);
    this.panY.set(box.clientHeight / 2 - ((minY + maxY) / 2) * scale);
  }

  pointerDown(event: PointerEvent): void {
    const target = event.currentTarget as HTMLElement;
    this.drag = { x: event.clientX, y: event.clientY, panX: this.panX(), panY: this.panY() };
    target.setPointerCapture(event.pointerId);
  }

  pointerMove(event: PointerEvent): void {
    if (!this.drag) return;
    this.panX.set(this.drag.panX + event.clientX - this.drag.x);
    this.panY.set(this.drag.panY + event.clientY - this.drag.y);
  }

  pointerUp(): void {
    this.drag = null;
  }

  selectedMarks(): SchedulePlacement[] {
    const key = this.selectedKey();
    const offer = this.offer();
    if (!key || !offer) return [];
    return offer.placements.filter((mark) => `${mark.board}|${mark.device_type}|${mark.rating}` === key);
  }

  applyGroup(group: TypeGroup, raw: string): void {
    const code = raw.trim();
    const current = this.offer();
    if (!current) return;
    this.selectGroup(group.key);
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

  private async drawPdf(): Promise<void> {
    const file = this.file;
    const canvas = this.canvas?.nativeElement;
    if (!file || !canvas) return;
    this.drawing.set(true);
    this.error.set('');
    try {
      const pdfjs = await import('pdfjs-dist');
      pdfjs.GlobalWorkerOptions.workerSrc = '/pdf.worker.min.mjs';
      const pdf = await pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
      const page = await pdf.getPage(1);
      const viewport = page.getViewport({ scale: 1.4 });
      canvas.width = viewport.width;
      canvas.height = viewport.height;
      this.sheetWidth.set(viewport.width);
      this.sheetHeight.set(viewport.height);
      const context = canvas.getContext('2d');
      if (!context) return;
      await page.render({ canvasContext: context, viewport }).promise;
      const frame = this.viewport?.nativeElement;
      if (frame && this.wheelTarget !== frame) {
        this.wheelTarget?.removeEventListener('wheel', this.onWheel);
        frame.addEventListener('wheel', this.onWheel, { passive: false });
        this.wheelTarget = frame;
      }
      this.fitSheet();
    } catch {
      this.error.set('Схемата не можа да се начертае, но кодовете в таблицата са прочетени.');
    } finally {
      this.drawing.set(false);
    }
  }
}
