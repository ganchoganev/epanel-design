import { NgTemplateOutlet } from '@angular/common';
import { Component, ElementRef, HostListener, Input, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../services/auth.service';

@Component({
  selector: 'app-bar',
  standalone: true,
  imports: [RouterLink, NgTemplateOutlet],
  host: { '[class.menu-only]': 'variant === "menu"' },
  template: `
    @if (variant === 'bar') {
      <header class="bar">
        <div class="brand">
          <span class="logo">ETI</span>
          <span class="title">{{ title }}</span>
        </div>
        <div class="actions">
          <ng-container *ngTemplateOutlet="menu" />
          <button type="button" class="exit" (click)="logout()">Изход</button>
        </div>
      </header>
    } @else {
      <ng-container *ngTemplateOutlet="menu" />
    }

    <ng-template #menu>
      <div class="upload">
        <button type="button" (click)="toggle($event)" [class.on]="open()" [attr.aria-expanded]="open()">
          Качи
        </button>
        @if (open()) {
          <div class="list" role="menu">
            <a routerLink="/data/prices" (click)="open.set(false)">
              Каталог и цени
              <small>Офертата чете каталога от базата. Тук се качва ценовата листа.</small>
            </a>
            <a routerLink="/data/codes" (click)="open.set(false)">
              Нови кодове
              <small>Шаблон за код, който го няма в каталога.</small>
            </a>
          </div>
        }
      </div>
    </ng-template>
  `,
  styles: `
    :host { display: block; }
    :host(.menu-only) { display: inline-flex; position: relative; }
    .bar {
      display: flex;
      align-items: center;
      gap: 16px;
      min-height: 56px;
      padding: 0 16px;
      background: #263238;
      color: #fff;
      position: sticky;
      top: 0;
      z-index: 30;
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
    }
    .logo {
      background: #d32f2f;
      color: #fff;
      font-weight: 800;
      padding: 4px 8px;
      border-radius: 4px;
      letter-spacing: 1px;
    }
    .title {
      font-weight: 600;
      font-size: 15px;
    }
    .actions button,
    .upload > button {
      background: #37474f;
      color: #eceff1;
      border: none;
      padding: 6px 10px;
      border-radius: 5px;
      cursor: pointer;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
    }
    .actions button:hover,
    .upload > button:hover {
      background: #455a64;
    }
    .upload > button.on {
      background: #2e7d32;
    }
    .actions {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-left: auto;
    }
    .upload {
      position: relative;
    }
    .list {
      position: absolute;
      top: calc(100% + 8px);
      right: 0;
      width: 260px;
      padding: 6px;
      background: #37474f;
      border: 1px solid #546e7a;
      border-radius: 8px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.28);
      z-index: 40;
    }
    .list a {
      display: block;
      padding: 8px 10px;
      border-radius: 6px;
      color: #fff;
      text-decoration: none;
    }
    .list a:hover {
      background: #455a64;
    }
    .list small {
      display: block;
      margin-top: 2px;
      color: #b0bec5;
      font-size: 12px;
      font-weight: 400;
      line-height: 1.35;
    }
    @media (max-width: 720px) {
      .bar {
        flex-wrap: wrap;
        padding: 8px 12px;
        gap: 8px;
      }
      .actions { margin-left: 0; }
    }
  `,
})
export class AppBarComponent {
  private auth = inject(AuthService);
  private host = inject(ElementRef<HTMLElement>);

  @Input() title = 'Panel Designer';
  @Input() variant: 'bar' | 'menu' = 'bar';

  readonly open = signal(false);

  toggle(event: MouseEvent): void {
    event.stopPropagation();
    this.open.update((value) => !value);
  }

  logout(): void {
    this.auth.logout();
  }

  @HostListener('document:click', ['$event'])
  closeOnOutside(event: MouseEvent): void {
    if (!this.host.nativeElement.contains(event.target)) {
      this.open.set(false);
    }
  }
}
