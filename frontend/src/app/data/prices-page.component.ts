import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { PriceImportComponent } from '../price-import/price-import.component';

@Component({
  selector: 'app-prices-page',
  standalone: true,
  imports: [RouterLink, PriceImportComponent],
  template: `
    <div class="page">
      <a class="back" routerLink="/">← Меню</a>
      <p class="kicker">Качва се веднъж</p>
      <h1>Цени</h1>
      <p class="lead">
        Тук се качва Excel-ът с актуална цена за всеки код. След записа новите оферти ползват тези цени, без да качваш файла отново.
      </p>
      <app-price-import />
    </div>
  `,
  styleUrl: './data-page.scss',
})
export class PricesPageComponent {}
