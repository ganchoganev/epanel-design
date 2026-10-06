import { Component } from '@angular/core';
import { PriceImportComponent } from '../price-import/price-import.component';
import { AppBarComponent } from '../steps/app-bar.component';

@Component({
  selector: 'app-prices-page',
  standalone: true,
  imports: [PriceImportComponent, AppBarComponent],
  template: `
    <app-bar title="Цени" />
    <div class="page">
      <p class="kicker">Качва се веднъж</p>
      <h1>Каталог и цени</h1>
      <p class="lead">Две отделни неща. Офертата чете каталога от базата. Ценовата листа само слага цени върху него.</p>
      <app-price-import />
    </div>
  `,
  styleUrl: './data-page.scss',
})
export class PricesPageComponent {}
