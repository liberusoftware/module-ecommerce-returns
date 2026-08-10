<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The host's team model
    |--------------------------------------------------------------------------
    |
    | A return belongs to a team, and the team belongs to the application rather
    | than to this package. So the class is resolved at call time and never
    | imported — a module that names `App\Models\Team` in a `use` statement
    | installs into exactly one application.
    |
    | The default is Jetstream's, which is what every Liberu application uses.
    |
    */

    'team_model' => env('RETURNS_TEAM_MODEL', 'App\\Models\\Team'),

    /*
    |--------------------------------------------------------------------------
    | The customer model
    |--------------------------------------------------------------------------
    |
    | `customer_id` is a plain indexed column with no foreign key: customers
    | belong to the host, or to CRM, and this package depends on neither. The id
    | alone is enough for every rule this module enforces.
    |
    | Name a class here and `ReturnRequest::customer()` becomes loadable, so a
    | panel can show a name instead of a number. Leave it null and the relation is
    | simply never used — asking for it without configuring it throws, rather than
    | guessing a class and failing later with a message about a missing table.
    |
    */

    'customer_model' => env('RETURNS_CUSTOMER_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Telemetry
    |--------------------------------------------------------------------------
    |
    | Structured records of this module's own domain events. Off by default: a
    | package that starts filling a deployment's log the moment it installs has
    | decided somebody else's retention bill.
    |
    | No personal data is ever written — and in particular never a return line's
    | `note`, which is the one free-text field in this package. Reasons travel as
    | slugs from a closed set, which is what makes them safe to log at all.
    |
    | Nothing here is exclusive: everything the logger writes is a domain event
    | any listener can subscribe to.
    |
    */

    'telemetry' => [
        'enabled' => (bool) env('RETURNS_TELEMETRY', false),
        'channel' => env('RETURNS_TELEMETRY_CHANNEL'),
    ],

];
