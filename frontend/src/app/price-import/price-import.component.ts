import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ApiService, PriceMapping, PricePreview } from '../services/api.service';

@Component({
  selector: 'app-price-import',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './price-import.component.html',
  styleUrls: ['./price-import.component.scss'],
})
export class PriceImportComponent {
  private api = inject(ApiService);

  file = signal<File | null>(null);
  headerRow = signal(1);
  preview = signal<PricePreview | null>(null);
  mapping = signal<PriceMapping>({ catalog_number: null, price: null, currency: null });
  loading = signal(false);
  result = signal<string | null>(null);
  error = signal<string | null>(null);

  onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.file.set(input.files?.[0] ?? null);
    this.preview.set(null);
    this.result.set(null);
  }

  loadPreview(): void {
    const file = this.file();
    if (!file) return;
    this.loading.set(true);
    this.error.set(null);
    this.api.previewPrices(file, this.headerRow()).subscribe({
      next: (res) => {
        this.preview.set(res);
        this.mapping.set(res.suggested_mapping);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err?.error?.message ?? 'Грешка при четене на файла.');
        this.loading.set(false);
      },
    });
  }

  columnIndexes(): number[] {
    const p = this.preview();
    if (!p) return [];
    return p.headers.map((_, i) => i);
  }

  setMapping(field: keyof PriceMapping, value: string): void {
    const v = value === '' ? null : Number(value);
    this.mapping.set({ ...this.mapping(), [field]: v });
  }

  canImport(): boolean {
    const m = this.mapping();
    return m.catalog_number !== null && m.price !== null && !!this.file();
  }

  runImport(): void {
    const file = this.file();
    if (!file || !this.canImport()) return;
    this.loading.set(true);
    this.error.set(null);
    this.api.importPrices(file, this.mapping(), this.headerRow()).subscribe({
      next: (res) => {
        this.result.set(
          `Обновени: ${res.updated}, ненамерени: ${res.notFound}, пропуснати: ${res.skipped}`
        );
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err?.error?.message ?? 'Грешка при импорта.');
        this.loading.set(false);
      },
    });
  }
}
