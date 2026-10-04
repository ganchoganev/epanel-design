import { Routes } from '@angular/router';
import { CodesPageComponent } from './data/codes-page.component';
import { PricesPageComponent } from './data/prices-page.component';
import { HomeComponent } from './home/home.component';
import { BoardViewComponent } from './offers/board-view.component';
import { OfferFromPdfComponent } from './offers/offer-from-pdf.component';
import { PanelWorkspaceComponent } from './panel-workspace/panel-workspace.component';
import { StepPageComponent, StepPageData } from './steps/step-page.component';

const apartmentTemplate: StepPageData = {
  track: 'Направление 2',
  step: 'Стъпка 1',
  title: 'Шаблон за кръгове',
  lead: 'Апартаментната схема се въвежда по фиксиран шаблон: кръг, помещение, товар, напрежение и апарат. Така разчитането не зависи от свободен текст.',
  points: [
    'Всеки ред е един токов кръг.',
    'След шаблона се минава по същия път: оферта с кодове на ETI и изглед на таблото.',
    'Шаблонът е отделен от автоматичното окабеляване.',
  ],
  note: 'Формата на шаблона още не е включена. Конфигураторът в последния етап остава там, докато този вход се направи нарочно.',
};

export const routes: Routes = [
  { path: '', component: HomeComponent },
  { path: 'data/prices', component: PricesPageComponent },
  { path: 'data/codes', component: CodesPageComponent },
  { path: 'drawing/offer', component: OfferFromPdfComponent },
  { path: 'drawing/views', component: BoardViewComponent },
  { path: 'drawing/3d', redirectTo: 'layout' },
  { path: 'apartment/template', component: StepPageComponent, data: apartmentTemplate },
  { path: 'layout', component: PanelWorkspaceComponent },
  { path: '**', redirectTo: '' },
];
