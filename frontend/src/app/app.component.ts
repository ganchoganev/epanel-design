import { Component, inject, signal } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { LoginComponent } from './login/login.component';
import { AuthService } from './services/auth.service';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, LoginComponent],
  templateUrl: './app.component.html',
  styleUrl: './app.component.scss',
})
export class AppComponent {
  readonly auth = inject(AuthService);
  readonly authReady = signal(false);

  constructor() {
    this.auth.hydrate().subscribe(() => this.authReady.set(true));
  }

  onLoggedIn(): void {
    this.authReady.set(true);
  }
}
