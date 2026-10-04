import { Component, inject, Input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ProjectPrintService } from '../offers/project-print.service';

@Component({
  selector: 'app-step-trail',
  standalone: true,
  imports: [RouterLink],
  template: `
    <nav class="steps" [class.compact]="compact" aria-label="Стъпки на проекта">
      <a class="menu" routerLink="/">Меню</a>
      <a routerLink="/drawing/offer" [class.on]="step === 1"><span>1</span> Оферта</a>
      <a routerLink="/drawing/views" [class.on]="step === 2"><span>2</span> 2D</a>
      <a routerLink="/layout" [class.on]="step === 3"><span>3</span> Табло</a>
      <button type="button" class="print" (click)="print()" [disabled]="printing()">
        {{ printing() ? 'Печат…' : 'Печат' }}
      </button>
      @if (message()) {
        <span class="msg">{{ message() }}</span>
      }
    </nav>
    @if (printing()) {
      <div class="busy" role="status" aria-live="polite">
        <span class="spin"></span>
        <span>Подготвя се печатът…</span>
      </div>
    }
  `,
  styles: `
    :host { display: block; }
    .steps {
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
      align-items: center;
      margin: 0 0 12px;
    }
    .steps.compact { margin: 0; }
    a {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      min-height: 28px;
      padding: 3px 8px;
      border-radius: 6px;
      background: #fff;
      border: 1px solid #cfd8dc;
      color: #37474f;
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
    }
    a span {
      display: inline-grid;
      place-items: center;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      background: #eceff1;
      font-size: 11px;
    }
    a.on {
      background: #2e7d32;
      border-color: #2e7d32;
      color: #fff;
    }
    a.on span {
      background: rgba(255, 255, 255, 0.22);
      color: #fff;
    }
    button.print {
      margin-left: auto;
      min-height: 28px;
      padding: 3px 10px;
      border-radius: 6px;
      border: 1px solid #2e7d32;
      background: #2e7d32;
      color: #fff;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
    }
    button.print:disabled {
      opacity: 0.6;
      cursor: wait;
    }
    .msg {
      font-size: 12px;
      color: #c62828;
    }
    .busy {
      position: fixed;
      inset: 0;
      z-index: 40;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 14px;
      background: rgba(255, 255, 255, 0.78);
      color: #263238;
      font-size: 16px;
      font-weight: 600;
    }
    .spin {
      width: 42px;
      height: 42px;
      border: 4px solid #cfd8dc;
      border-top-color: #2e7d32;
      border-radius: 50%;
      animation: turn 0.8s linear infinite;
    }
    @keyframes turn {
      to { transform: rotate(360deg); }
    }
  `,
})
export class StepTrailComponent {
  private printer = inject(ProjectPrintService);

  @Input() step = 1;
  @Input() compact = false;

  readonly printing = signal(false);
  readonly message = signal('');

  async print(): Promise<void> {
    this.printing.set(true);
    this.message.set('');
    await new Promise((resolve) => requestAnimationFrame(() => resolve(undefined)));
    await new Promise((resolve) => setTimeout(resolve, 40));
    try {
      await this.printer.download();
    } catch (error) {
      this.message.set(error instanceof Error ? error.message : 'Печатът не успя.');
    } finally {
      this.printing.set(false);
    }
  }
}
