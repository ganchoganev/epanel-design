import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, catchError, map, of, tap } from 'rxjs';
import { environment } from '../../environments/environment';

const TOKEN_KEY = 'eti_panel_token';

export interface AuthUser {
  name: string;
  email: string;
}

interface LoginResponse {
  token: string;
  user: AuthUser;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private http = inject(HttpClient);
  private base = environment.apiUrl;

  readonly token = signal<string | null>(localStorage.getItem(TOKEN_KEY));
  readonly user = signal<AuthUser | null>(null);
  readonly isLoggedIn = computed(() => !!this.token());

  login(email: string, password: string): Observable<void> {
    return this.http.post<LoginResponse>(`${this.base}/login`, { email, password }).pipe(
      tap((res) => this.setSession(res.token, res.user)),
      map(() => undefined)
    );
  }

  logout(): void {
    const token = this.token();
    if (token) {
      this.http.post(`${this.base}/logout`, {}).subscribe({ error: () => undefined });
    }
    this.clear();
  }

  hydrate(): Observable<boolean> {
    if (!this.token()) return of(false);
    return this.http.get<AuthUser>(`${this.base}/me`).pipe(
      tap((user) => this.user.set(user)),
      map(() => true),
      catchError(() => {
        this.clear();
        return of(false);
      })
    );
  }

  clear(): void {
    this.token.set(null);
    this.user.set(null);
    localStorage.removeItem(TOKEN_KEY);
  }

  private setSession(token: string, user: AuthUser): void {
    this.token.set(token);
    this.user.set(user);
    localStorage.setItem(TOKEN_KEY, token);
  }
}
