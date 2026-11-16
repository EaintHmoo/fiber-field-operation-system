<?php

namespace App\Providers;

use App\Enums\RequestType;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use App\Repositories\Contracts\BookingLogRepositoryInterface;
use App\Repositories\Contracts\CapacityRepositoryInterface;
use App\Repositories\Eloquent\EloquentAppointmentRepository;
use App\Repositories\Eloquent\EloquentBookingLogRepository;
use App\Repositories\Eloquent\EloquentCapacityRepository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AppointmentRepositoryInterface::class, EloquentAppointmentRepository::class);
        $this->app->bind(CapacityRepositoryInterface::class, EloquentCapacityRepository::class);
        $this->app->bind(BookingLogRepositoryInterface::class, EloquentBookingLogRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short, stable request_type values for appointment_booking_logs instead of class names.
        Relation::morphMap(RequestType::morphMap());
    }
}
