import { Component, inject, OnDestroy, OnInit, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { OfferDraftStore } from './offer-draft.store';
import { ApiService, ScheduleOffer } from '../services/api.service';
import { StepTrailComponent } from '../steps/step-trail.component';
import {
  EnclosureOption,
  deviceModules,
  loadEnclosureChoices,
  packDevices,
  PackedDevice,
  saveEnclosureChoice,
  suggestEnclosure,
} from './board-fit';

interface FaceSize {
  width_mm: number;
  height_mm: number;
}

type Board = ScheduleOffer['boards'][number];

@Component({
  selector: 'app-board-photo',
  standalone: true,
  imports: [RouterLink, StepTrailComponent],
  templateUrl: './board-photo.component.html',
  styleUrl: './board-photo.component.scss',
})
export class BoardPhotoComponent implements OnInit, OnDestroy {
  private store = inject(OfferDraftStore);
  private api = inject(ApiService);

  readonly offer = signal<ScheduleOffer | null>(null);
  readonly enclosures = signal<EnclosureOption[]>([]);
  readonly sizes = signal<Record<string, FaceSize | null>>({});
  readonly photos = signal<Record<string, string | null>>({});
  readonly picked = signal<Record<string, string>>({});
  private urls: string[] = [];
  private loadingFaces = new Set<string>();
  private loadingPhotos = new Set<string>();

  ngOnInit(): void {
    this.offer.set(this.store.load());
    this.picked.set(loadEnclosureChoices());
    this.api.enclosures().subscribe({
      next: (body) => {
        this.enclosures.set(body.enclosures);
        this.refresh();
      },
    });
    const offer = this.offer();
    if (!offer) return;
    for (const board of offer.boards) {
      for (const line of board.lines) {
        this.loadSize(line.catalog_number);
        this.loadPhoto(line.catalog_number);
      }
    }
  }

  ngOnDestroy(): void {
    for (const url of this.urls) URL.revokeObjectURL(url);
  }

  flush(): EnclosureOption[] {
    return this.enclosures().filter((option) => option.mounting === 'вграден');
  }

  surface(): EnclosureOption[] {
    return this.enclosures().filter((option) => option.mounting === 'открит');
  }

  option(code: string | undefined): EnclosureOption | undefined {
    return this.enclosures().find((item) => item.catalog_number === code);
  }

  choose(board: string, code: string): void {
    this.picked.update((current) => ({ ...current, [board]: code }));
    saveEnclosureChoice(board, code);
    this.loadPhoto(code);
  }

  rows(board: Board): PackedDevice[][] {
    const option = this.option(this.picked()[board.name]);
    if (!option) return [];
    const packed = packDevices(
      board.lines,
      (code) => {
        const size = this.sizes()[code];
        return size ? deviceModules(size.width_mm) : 1;
      },
      (code) => this.sizes()[code]?.height_mm ?? 90,
      option.rows,
      option.modules_per_row,
    );
    const grouped: PackedDevice[][] = Array.from({ length: option.rows }, () => []);
    for (const device of packed.placed) grouped[device.row].push(device);
    return grouped.filter((row) => row.length > 0);
  }

  private refresh(): void {
    const offer = this.offer();
    if (!offer || !this.enclosures().length) return;
    const next = { ...this.picked() };
    let changed = false;
    for (const board of offer.boards) {
      const current = next[board.name];
      if (current && this.option(current)) {
        this.loadPhoto(current);
        continue;
      }
      const demand = this.demand(board);
      if (!demand) continue;
      const suggestion = suggestEnclosure(this.enclosures(), demand.total, demand.widest);
      if (!suggestion) continue;
      next[board.name] = suggestion.catalog_number;
      saveEnclosureChoice(board.name, suggestion.catalog_number);
      this.loadPhoto(suggestion.catalog_number);
      changed = true;
    }
    if (changed) this.picked.set(next);
  }

  private demand(board: Board): { total: number; widest: number } | null {
    let total = 0;
    let widest = 1;
    for (const line of board.lines) {
      const size = this.sizes()[line.catalog_number];
      if (size === undefined) return null;
      const modules = size ? deviceModules(size.width_mm) : 1;
      total += modules * line.quantity;
      widest = Math.max(widest, modules);
    }
    return { total, widest };
  }

  private loadSize(code: string): void {
    if (!code || this.loadingFaces.has(code) || this.sizes()[code] !== undefined) return;
    this.loadingFaces.add(code);
    this.api.productFace(code).subscribe({
      next: (face) => {
        this.sizes.update((current) => ({
          ...current,
          [code]: { width_mm: face.width_mm, height_mm: face.height_mm },
        }));
        this.refresh();
      },
      error: () => {
        this.sizes.update((current) => ({ ...current, [code]: null }));
        this.refresh();
      },
    });
  }

  private loadPhoto(code: string): void {
    if (!code || this.loadingPhotos.has(code) || this.photos()[code] !== undefined) return;
    this.loadingPhotos.add(code);
    this.api.productPhoto(code).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        this.urls.push(url);
        this.photos.update((current) => ({ ...current, [code]: url }));
      },
      error: () => this.photos.update((current) => ({ ...current, [code]: null })),
    });
  }
}
