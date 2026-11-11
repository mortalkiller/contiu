<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Receivable;

function bankRow(string $date, string $description, int $amount): array
{
    return ['booked_on' => $date, 'description' => $description, 'amount' => $amount, 'balance' => null];
}

function receivableFor(string $clientName, int $amount, string $dueOn): Receivable
{
    $client = Client::factory()->create(['name' => $clientName]);
    $project = Project::factory()->for($client)->create(['net_amount' => $amount]);

    return Receivable::factory()->for($project, 'billable')->create(['amount' => $amount, 'due_on' => $dueOn]);
}
