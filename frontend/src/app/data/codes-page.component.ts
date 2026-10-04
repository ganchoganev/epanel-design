import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, OnInit, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ApiService, CodeReplacement } from '../services/api.service';

@Component({
  selector: 'app-codes-page',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './codes-page.component.html',
  styleUrl: './data-page.scss',
})
export class CodesPageComponent implements OnInit {
  private api = inject(ApiService);

  readonly busy = signal(false);
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

  upload(): void {
    if (!this.file) {
      this.error.set('Изберете файл.');
      return;
    }
    this.busy.set(true);
    this.error.set('');
    this.api.importCodeReplacements(this.file).subscribe({
      next: (res) => {
        this.busy.set(false);
        this.message.set(`Записани замени: ${res.count}. Пропуснати редове: ${res.skipped}.`);
        this.ngOnInit();
      },
      error: (err: HttpErrorResponse) => {
        this.busy.set(false);
        this.error.set(err?.error?.message || 'Файлът не можа да се прочете.');
      },
    });
  }
}
