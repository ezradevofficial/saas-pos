<?php

namespace Modules\Hotel\Search;

use App\Core\Search\RecordHit;
use App\Core\Search\RecordSearcher;
use App\Core\Search\SearchContext;
use App\Core\Search\SearchTerm;
use Modules\Hotel\HotelServiceProvider;
use Modules\Hotel\Http\Resources\GuestResource;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Models\Reservation;

/**
 * LAY-01, HOT-11: reservations by confirmation code (the reservation number)
 * or guest name, at the branches where the user holds
 * `hotel.reservation.view` (RBAC-04). The guest's name is neither matched nor
 * shown when the user's field rules hide it (RBAC-05, resource `hotel_guest`).
 * Trigram indexes serve the number and the guest name.
 */
class ReservationSearcher implements RecordSearcher
{
    public function type(): string
    {
        return 'reservation';
    }

    public function module(): string
    {
        return HotelServiceProvider::MODULE;
    }

    public function permission(): string
    {
        return 'hotel.reservation.view';
    }

    public function search(SearchTerm $term, SearchContext $context): array
    {
        $showsName = ! $context->hides(GuestResource::RESOURCE, 'name');

        $query = Reservation::query()->where(function ($q) use ($term, $showsName) {
            $q->whereRaw('number ilike ?', [$term->contains()]);

            if ($showsName) {
                $q->orWhereIn('guest_id', Guest::query()->select('id')->whereRaw('name ~* ?', [$term->regex()]));
            }
        });
        $context->whereAt($query, 'branch', 'branch_id');

        $reservations = $query
            ->orderByRaw('(number ilike ?) desc, created_at desc', [$term->prefix()])
            ->limit($term->limit)
            ->get(['id', 'number', 'guest_id']);

        $names = $showsName
            ? Guest::query()->whereKey($reservations->pluck('guest_id')->filter()->all())->pluck('name', 'id')
            : collect();

        return $reservations
            ->map(fn (Reservation $reservation) => new RecordHit(
                $reservation->id,
                (string) $reservation->number,
                $names[$reservation->guest_id] ?? null,
                "/hotel/reservations/{$reservation->id}",
            ))
            ->all();
    }
}
