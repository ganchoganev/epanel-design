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
  mapping = signal<PriceMapping>({ catalog_number: null, name: null, model: null, price: null, currency: null });
  catalogBusy = signal(false);
  catalogMessage = signal<string | null>(null);
  catalogFile: File | null = null;
  loading = signal(false);
  waitText = signal('');
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
    this.waitText.set('Чета ценовата листа…');
    this.error.set(null);
    this.api.previewPrices(file, this.headerRow()).subscribe({
      next: (res) => {
        this.preview.set(res);
        this.mapping.set(res.suggested_mapping);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(this.failure(err, 'Грешка при четене на файла.'));
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

  onCatalogFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.catalogFile = input.files?.[0] ?? null;
    this.catalogMessage.set(null);
    this.error.set(null);
  }

  downloadCatalog(): void {
    this.catalogBusy.set(true);
    this.waitText.set('Сваля се каталогът от базата…');
    this.error.set(null);
    this.api.downloadCatalogFile().subscribe({
      next: (blob) => {
        this.catalogBusy.set(false);
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'katalog.csv';
        link.click();
        URL.revokeObjectURL(url);
      },
      error: () => {
        this.catalogBusy.set(false);
        this.error.set('Каталогът не можа да се свали.');
      },
    });
  }

  uploadCatalog(): void {
    if (!this.catalogFile) {
      this.error.set('Изберете файла с каталога.');
      return;
    }
    this.catalogBusy.set(true);
    this.waitText.set('Записвам каталога в базата…');
    this.catalogMessage.set(null);
    this.error.set(null);
    this.api.importCatalogFile(this.catalogFile).subscribe({
      next: (res) => {
        this.catalogBusy.set(false);
        this.catalogMessage.set(`Каталогът е в базата. Нови: ${res.imported}. Обновени: ${res.updated}. Пропуснати: ${res.skipped}.`);
      },
      error: (err) => {
        this.catalogBusy.set(false);
        this.error.set(this.failure(err, 'Каталогът не можа да се запише.'));
      },
    });
  }

  runImport(): void {
    const file = this.file();
    if (!file || !this.canImport()) return;
    this.loading.set(true);
    this.waitText.set('Записвам цените…');
    this.error.set(null);
    this.api.importPrices(file, this.mapping(), this.headerRow()).subscribe({
      next: (res) => {
        this.result.set(
          `Записани цени: обновени ${res.updated}, нови кодове ${res.created ?? 0}, пропуснати ${res.skipped}`
        );
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(this.failure(err, 'Грешка при импорта.'));
        this.loading.set(false);
      },
    });
  }

  private failure(err: { status?: number; error?: { message?: string } }, fallback: string): string {
    if (err?.status === 0) {
      return 'Няма връзка със сървъра. Операцията спря, преди да върне отговор.';
    }
    return err?.error?.message || fallback;
  }
}
