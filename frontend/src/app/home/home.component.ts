import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AppBarComponent } from '../steps/app-bar.component';

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [RouterLink, AppBarComponent],
  templateUrl: './home.component.html',
  styleUrl: './home.component.scss',
})
export class HomeComponent {}
