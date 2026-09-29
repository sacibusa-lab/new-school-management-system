<?php

namespace App\Providers;

use App\Models\AcademicSession;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface accidental N+1 queries. In development we log them rather
        // than throw, so a stray lazy load never takes a page down mid-build.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            logger()->warning('Lazy loading detected', [
                'model' => $model::class,
                'relation' => $relation,
            ]);
        });

        $this->shareBranding();
    }

    /**
     * Every view gets the school's branding without each controller asking.
     * Wrapped in a try/catch so a missing settings table (fresh clone, before
     * `migrate --seed`) never breaks the whole application.
     */
    protected function shareBranding(): void
    {
        // Resolved on first use and reused for the rest of the request. Deliberately
        // lazy rather than computed here: boot() runs before anything is rendering,
        // and the answer must reflect the database as it is when the page is built.
        $this->app->singleton('saci.public_registration_open', function (): bool {
            try {
                // Self-registration needs BOTH the switch on and the session open
                // for admissions. The office can always register applicants from
                // inside; this only governs the public form.
                return (bool) Setting::get('registration_open', false)
                    && (bool) AcademicSession::current()?->is_admission_open;
            } catch (\Throwable) {
                return false;
            }
        });

        View::composer('*', function ($view) {
            // Resolved per render rather than once at boot. Booting happens before
            // anything is drawn, and the office can change the school's name or
            // upload a logo — the very next page has to show it. The reads behind
            // this are memoised, so asking again costs nothing.
            $view->with('school', (object) $this->branding());

            // Shared with every view because the public header, footer and landing
            // page all change with it, and those render on pages that never pass it.
            $view->with('registrationOpen', $this->app->make('saci.public_registration_open'));
        });
    }

    /**
     * The school's branding, with fallbacks so a missing settings table (fresh
     * clone, before `migrate --seed`) never breaks the whole application.
     *
     * @return array<string,mixed>
     */
    protected function branding(): array
    {
        try {
            return [
                'name' => Setting::get('school_name', config('saci.school_name')),
                'tagline' => Setting::get('school_tagline', config('saci.tagline')),
                'email' => Setting::get('contact_email'),
                'phone' => Setting::get('contact_phone'),
                'address' => Setting::get('contact_address'),
                'logo' => Setting::get('school_logo'),
                'favicon' => Setting::get('school_favicon'),
                'currency' => Setting::get('currency_symbol', '₦'),
                'code' => Setting::get('currency', 'NGN'),
            ];
        } catch (\Throwable) {
            return [
                'name' => config('saci.school_name'),
                'tagline' => config('saci.tagline'),
                'email' => null,
                'phone' => null,
                'address' => null,
                'logo' => null,
                'favicon' => null,
                'currency' => '₦',
                'code' => 'NGN',
            ];
        }
    }
}
