import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, OnInit, signal } from '@angular/core';
import { ApiService, CodeReplacement } from '../services/api.service';
import { AppBarComponent } from '../steps/app-bar.component';

@Component({
  selector: 'app-codes-page',
  standalone: true,
  imports: [AppBarComponent],
  templateUrl: './codes-page.component.html',
  styleUrl: './data-page.scss',
})
export class CodesPageComponent implements OnInit {
  private api = inject(ApiService);

  readonly busy = signal(false);
  readonly waitText = signal('');
  readonly error = signal('');
  readonly message = signal('');
  readonly rows = signal<CodeReplacement[]>([]);
  readonly count = signal(0);
  file: File | null = null;

  ngOnInit(): void {
    this.api.codeReplacements().subscribe({
      next: (res) => {
        this.count.set(res.count);
        this.rows.set(res.rows);
      },
      error: () => this.error.set('Списъкът със замени не можа да се зареди.'),
    });
  }

  onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.file = input.files?.[0] ?? null;
    this.message.set('');
    this.error.set('');
  }

  downloadTemplate(): void {
    this.busy.set(true);
    this.waitText.set('Сваля се шаблонът…');
    this.error.set('');
    this.api.downloadCodeTemplate().subscribe({
      next: (blob) => {
        this.busy.set(false);
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'novi-kodove.xlsx';
        link.click();
        URL.revokeObjectURL(url);
      },
      error: () => {
        this.busy.set(false);
        this.error.set('Шаблонът не можа да се свали.');
      },
    });
  }

  upload(): void {
    if (!this.file) {
      this.error.set('Изберете попълнения шаблон.');
      return;
    }
    this.busy.set(true);
    this.waitText.set('Записвам кодовете…');
    this.error.set('');
    this.api.importCodeSupplement(this.file).subscribe({
      next: (res) => {
        this.busy.set(false);
        this.message.set(`Нови кодове: ${res.imported}. Замени: ${res.replaced}. Връзки към схема: ${res.linked}. Пропуснати редове: ${res.skipped}.`);
        this.ngOnInit();
      },
      error: (err: HttpErrorResponse) => {
        this.busy.set(false);
        this.error.set(err?.error?.message || 'Файлът не можа да се прочете.');
      },
    });
  }

}
