import { Component, inject, OnDestroy, OnInit, signal } from '@angular/core';
import { OfferDraftStore } from './offer-draft.store';
import { ApiService, ProductFace, ScheduleOffer } from '../services/api.service';
import { AppBarComponent } from '../steps/app-bar.component';
import { StepTrailComponent } from '../steps/step-trail.component';
import {
  EnclosureOption,
  FaceRail,
  deviceModules,
  loadEnclosureChoices,
  packDevices,
  PackedDevice,
  railsOrFallback,
  saveEnclosureChoice,
  suggestEnclosure,
} from './board-fit';

interface FaceView {
  url: string;
  width_mm: number;
  height_mm: number;
  rails: FaceRail[];
}

type Board = ScheduleOffer['boards'][number];

@Component({
  selector: 'app-board-view',
  standalone: true,
  imports: [StepTrailComponent, AppBarComponent],
  templateUrl: './board-view.component.html',
  styleUrl: './board-view.component.scss',
})
export class BoardViewComponent implements OnInit, OnDestroy {
  private store = inject(OfferDraftStore);
  private api = inject(ApiService);
  readonly offer = signal<ScheduleOffer | null>(null);
  readonly enclosures = signal<EnclosureOption[]>([]);
  readonly faces = signal<Record<string, FaceView | null>>({});
  readonly picked = signal<Record<string, string>>({});
  readonly pxPerMm = 1.8;
  private urls: string[] = [];
  private loading = new Set<string>();

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
      for (const line of board.lines) this.loadFace(line.catalog_number);
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
    return this.enclosures().find((option) => option.catalog_number === code);
  }

  choose(board: string, code: string): void {
    this.picked.update((current) => ({ ...current, [board]: code }));
    saveEnclosureChoice(board, code);
    this.loadFace(code);
  }

  proposal(board: Board): EnclosureOption | null {
    const demand = this.demand(board);
    if (!demand) return null;
    return suggestEnclosure(this.enclosures(), demand.total, demand.widest);
  }

  pack(board: Board): { placed: PackedDevice[]; leftover: number } {
    const option = this.option(this.picked()[board.name]);
    if (!option) return { placed: [], leftover: 0 };
    return packDevices(
      board.lines,
      (code) => {
        const face = this.faces()[code];
        return face ? deviceModules(face.width_mm) : 1;
      },
      (code) => this.faces()[code]?.height_mm ?? 90,
      option.rows,
      option.modules_per_row,
    );
  }

  railsFor(board: Board): FaceRail[] {
    const code = this.picked()[board.name];
    const option = this.option(code);
    const face = code ? this.faces()[code] : undefined;
    if (!option || !face) return [];
    return railsOrFallback(face.rails, option.rows, option.modules_per_row, face.width_mm, face.height_mm);
  }

  used(board: Board): number {
    return this.pack(board).placed.reduce((sum, device) => sum + device.modules, 0);
  }

  capacity(board: Board): number {
    const option = this.option(this.picked()[board.name]);
    return option ? option.rows * option.modules_per_row : 0;
  }

  tall(board: Board): string[] {
    const names = new Set<string>();
    for (const device of this.pack(board).placed) {
      if (device.heightMm > 130) names.add(`${device.code} (${Math.round(device.heightMm)} mm)`);
    }
    return [...names];
  }

  private refresh(): void {
    const offer = this.offer();
    if (!offer || !this.enclosures().length) return;
    const next = { ...this.picked() };
    let changed = false;
    for (const board of offer.boards) {
      const current = next[board.name];
      if (current && this.option(current)) {
        this.loadFace(current);
        continue;
      }
      const demand = this.demand(board);
      if (!demand) continue;
      const suggestion = suggestEnclosure(this.enclosures(), demand.total, demand.widest);
      if (!suggestion) continue;
      next[board.name] = suggestion.catalog_number;
      saveEnclosureChoice(board.name, suggestion.catalog_number);
      this.loadFace(suggestion.catalog_number);
      changed = true;
    }
    if (changed) this.picked.set(next);
  }

  private demand(board: Board): { total: number; widest: number } | null {
    let total = 0;
    let widest = 1;
    for (const line of board.lines) {
      const face = this.faces()[line.catalog_number];
      if (face === undefined) return null;
      const modules = face ? deviceModules(face.width_mm) : 1;
      total += modules * line.quantity;
      widest = Math.max(widest, modules);
    }
    return { total, widest };
  }

  private loadFace(code: string): void {
    if (!code || this.loading.has(code) || this.faces()[code] !== undefined) return;
    this.loading.add(code);
    this.api.productFace(code).subscribe({
      next: (face) => this.storeFace(code, face),
      error: () => {
        this.faces.update((current) => ({ ...current, [code]: null }));
        this.refresh();
      },
    });
  }

  private storeFace(code: string, face: ProductFace): void {
    const url = URL.createObjectURL(new Blob([face.svg], { type: 'image/svg+xml' }));
    this.urls.push(url);
    this.faces.update((current) => ({
      ...current,
      [code]: {
        url,
        width_mm: face.width_mm,
        height_mm: face.height_mm,
        rails: face.rails ?? [],
      },
    }));
    this.refresh();
  }
}
