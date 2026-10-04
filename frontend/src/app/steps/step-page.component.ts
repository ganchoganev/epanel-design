import { Component, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { map } from 'rxjs';

export interface StepPageData {
  track: string;
  step: string;
  title: string;
  lead: string;
  points: string[];
  note: string;
}

@Component({
  selector: 'app-step-page',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './step-page.component.html',
  styleUrl: './step-page.component.scss',
})
export class StepPageComponent {
  private route = inject(ActivatedRoute);

  readonly page = toSignal(
    this.route.data.pipe(map((data) => data as StepPageData)),
    { requireSync: true }
  );
}
