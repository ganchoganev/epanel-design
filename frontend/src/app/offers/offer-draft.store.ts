import { Injectable } from '@angular/core';
import { ScheduleOffer } from '../services/api.service';

const KEY = 'eti_last_offer';

@Injectable({ providedIn: 'root' })
export class OfferDraftStore {
  save(offer: ScheduleOffer): void {
    sessionStorage.setItem(KEY, JSON.stringify(offer));
  }

  load(): ScheduleOffer | null {
    const raw = sessionStorage.getItem(KEY);
    if (!raw) return null;
    try {
      return JSON.parse(raw) as ScheduleOffer;
    } catch {
      return null;
    }
  }
}
