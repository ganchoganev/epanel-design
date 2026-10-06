<?php

namespace App\Services\Offers;

class OfferDraft
{
    /**
     * @param  list<OfferBoard>  $boards
     * @param  list<array{board: string, rating: string, device_type: string, quantity: int, reason: string}>  $unmatched
     * @param  list<array{id: int, board: string, x: float, y: float, file: string, device_type: string, rating: string, catalog_number: ?string, name: ?string, unit_price: ?float}>  $placements
     * @param  list<array{board: string, text: string, quantity: int, reason: string, x?: float, y?: float, file?: string}>  $unread
     */
    public function __construct(
        public readonly array $boards,
        public readonly array $unmatched,
        public readonly string $note,
        public readonly array $placements = [],
        public readonly array $unread = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'boards' => array_map(fn (OfferBoard $board) => [
                'name' => $board->name,
                'quantity' => $board->quantity,
                'lines' => array_map(fn (OfferLine $line) => [
                    'catalog_number' => $line->catalogNumber,
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                ], $board->lines),
            ], $this->boards),
            'unmatched' => $this->unmatched,
            'note' => $this->note,
            'placements' => $this->placements,
            'unread' => $this->unread,
        ];
    }
}
