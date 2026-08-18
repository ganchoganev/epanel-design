import { Component, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../services/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [FormsModule],
  template: `
    <div class="login-screen">
      <form class="login-card" (ngSubmit)="submit()">
        <div class="brand">
          <span class="logo">ETI</span>
          <span class="title">Panel Designer</span>
        </div>
        <p class="hint">Влезте с имейл и парола, зададени в .env на сървъра.</p>
        <label>
          Имейл
          <input
            type="email"
            name="email"
            autocomplete="username"
            [(ngModel)]="email"
            required
          />
        </label>
        <label>
          Парола
          <input
            type="password"
            name="password"
            autocomplete="current-password"
            [(ngModel)]="password"
            required
          />
        </label>
        @if (error()) {
          <p class="error">{{ error() }}</p>
        }
        <button type="submit" class="primary" [disabled]="busy()">Вход</button>
      </form>
    </div>
  `,
  styles: [
    `
      .login-screen {
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #263238;
        padding: 24px;
      }
      .login-card {
        width: 100%;
        max-width: 380px;
        background: #fff;
        border-radius: 10px;
        padding: 28px 24px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.28);
        display: flex;
        flex-direction: column;
        gap: 12px;
      }
      .brand {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 4px;
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
        color: #263238;
      }
      .hint {
        margin: 0 0 8px;
        font-size: 13px;
        color: #607d8b;
      }
      label {
        display: flex;
        flex-direction: column;
        gap: 4px;
        font-size: 13px;
        color: #546e7a;
      }
      input {
        padding: 10px 12px;
        border: 1px solid #cfd8dc;
        border-radius: 6px;
        font-size: 15px;
      }
      .error {
        margin: 0;
        color: #c62828;
        font-size: 13px;
      }
      .primary {
        margin-top: 8px;
        background: #2e7d32;
        color: #fff;
        border: none;
        padding: 12px;
        border-radius: 6px;
        font-size: 15px;
        cursor: pointer;
      }
      .primary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }
    `,
  ],
})
export class LoginComponent {
  readonly loggedIn = output<void>();

  email = '';
  password = '';
  readonly busy = signal(false);
  readonly error = signal('');

  constructor(private auth: AuthService) {}

  submit(): void {
    this.error.set('');
    this.busy.set(true);
    this.auth.login(this.email.trim(), this.password).subscribe({
      next: () => this.loggedIn.emit(),
      error: (err) => {
        this.busy.set(false);
        const msg =
          err?.error?.errors?.email?.[0] ||
          err?.error?.message ||
          'Неуспешен вход.';
        this.error.set(msg);
      },
      complete: () => this.busy.set(false),
    });
  }
}
