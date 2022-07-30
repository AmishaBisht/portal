<?php

namespace Modules\Client\Entities;

use App\Traits\Filters;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\User\Entities\User;
use Modules\Project\Entities\Project;
use Illuminate\Database\Eloquent\Model;
use Modules\Client\Database\Factories\ClientFactory;
use Modules\Client\Entities\Traits\HasHierarchy;
use Modules\Client\Entities\Scopes\ClientGlobalScope;
use Modules\Invoice\Entities\Invoice;
use Modules\Invoice\Services\InvoiceService;

class Client extends Model
{
    use HasHierarchy, HasFactory, Filters;

    protected $fillable = ['name', 'key_account_manager_id', 'status', 'is_channel_partner', 'has_departments', 'channel_partner_id', 'parent_organisation_id', 'client_id'];

    protected $appends = ['type', 'currency'];

    protected static function booted()
    {
        static::addGlobalScope(new ClientGlobalScope);
    }

    protected static function newFactory()
    {
        return new ClientFactory();
    }

    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function keyAccountManager()
    {
        return $this->belongsTo(User::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function projectLevelBillingProjects()
    {
        return $this->hasMany(Project::class)->select('projects.*')
            ->join('project_meta', function ($join) {
                $join->on('project_meta.project_id', '=', 'projects.id');
                $join->where([
                    'project_meta.key' => config('project.meta_keys.billing_level.key'),
                    'project_meta.value' => config('project.meta_keys.billing_level.value.project.key')
                ]);
            });
    }

    public function clientLevelBillingProjects()
    {
        return $this->hasMany(Project::class)->select('projects.*')
            ->join('project_meta', function ($join) {
                $join->on('project_meta.project_id', '=', 'projects.id');
                $join->where([
                    'project_meta.key' => config('project.meta_keys.billing_level.key'),
                    'project_meta.value' => config('project.meta_keys.billing_level.value.client.key')
                ]);
            });
    }

    public function getReferenceIdAttribute()
    {
        return sprintf('%03s', $this->id);
    }

    public function contactPersons()
    {
        return $this->hasMany(ClientContactPerson::class);
    }

    public function getBillingContactAttribute()
    {
        return $this->contactPersons()->where('type', config('client.client-contact-person-type.primary-billing-contact'))->first();
    }

    public function secondaryContacts()
    {
        return $this->contactPersons()->where('type', config('client.client-contact-person-type.secondary-billing-contact'));
    }

    public function tertiaryContacts()
    {
        return $this->contactPersons()->where('type', config('client.client-contact-person-type.tertiary-billing-contact'));
    }

    public function addresses()
    {
        return $this->hasMany(ClientAddress::class);
    }

    public function billingDetails()
    {
        return $this->hasOne(ClientBillingDetail::class)->withDefault();
    }

    public function getTypeAttribute()
    {
        $address = $this->addresses->first();
        if (! $address) {
            return;
        }

        return  $address->country_id == '1' ? 'indian' : 'international';
    }

    public function getCountryAttribute()
    {
        return optional($this->addresses->first())->country;
    }

    public function getCurrencyAttribute()
    {
        return $this->type == 'indian' ? 'INR' : 'USD';
    }

    public function getBillableAmountForTerm(int $monthsToSubtract, $projects)
    {
        $monthsToSubtract = $monthsToSubtract ?? 1;
        $amount = $projects->sum(function ($project) use ($monthsToSubtract) {
            return round($project->getBillableHoursForMonth($monthsToSubtract) * $this->billingDetails->service_rates, 2);
        });

        return $amount;
    }

    public function getTaxAmountForTerm(int $monthsToSubtract, $projects)
    {
        $monthsToSubtract = $monthsToSubtract ?? 1;
        // Todo: Implement tax calculation correctly as per the GST rules
        return round($this->getBillableAmountForTerm($monthsToSubtract, $projects) * ($this->country->initials == 'IN' ? config('invoice.tax-details.igst') : 0), 2);
    }

    public function getTotalPayableAmountForTerm(int $monthsToSubtract, $projects = null)
    {
        $monthsToSubtract = $monthsToSubtract ?? 1;
        $projects = $projects ?? collect([]);

        return $this->getBillableAmountForTerm($monthsToSubtract, $projects) + $this->getTaxAmountForTerm($monthsToSubtract, $projects);
    }

    public function getAmountPaidForTerm(int $monthsToSubtract, $projects)
    {
        // This needs to be updated based on the requirements.
        return 0.00;
    }

    public function getCurrentHoursInProjectsAttribute()
    {
        return $this->projects->sum(function ($project) {
            return $project->current_hours_for_month;
        });
    }

    public function getClientLevelProjectsBillableHoursForInvoice($monthsToSubtract)
    {
        $monthsToSubtract = $monthsToSubtract ?? 1;

        return $this->clientLevelBillingProjects->sum(function ($project) use ($monthsToSubtract) {
            return $project->getBillableHoursForMonth($monthsToSubtract);
        });
    }

    public function getNextInvoiceNumberAttribute()
    {
        $invoiceService = new InvoiceService();

        return $invoiceService->getInvoiceNumberPreview($this, null, today(), config('project.meta_keys.billing_level.value.client.key'));
    }

    public function getWorkingDaysForTerm()
    {
        $monthStartDate = $this->month_start_date;
        $monthEndDate = $this->month_end_date;

        return $this->getWorkingDays($monthStartDate, $monthEndDate);
    }

    public function getWorkingDays($startDate, $endDate)
    {
        return $endDate->addDay()->diffInDaysFiltered(function (Carbon $date) {
            return $date->isWeekday();
        }, $startDate);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeInvoiceReadyToSend($query)
    {
        return $query->whereDoesntHave('invoices', function ($query) {
            return $query->whereMonth('sent_on', now(''))->whereYear('sent_on', now(''));
        })->whereHas('billingDetails', function ($query) {
            return $query->where('billing_date', '<=', today()->format('d'));
        });
    }

    public function getEffortSheetUrlAttribute()
    {
        foreach ($this->clientLevelBillingProjects as $project) {
            if ($project->effort_sheet_url) {
                return $project->effort_sheet_url;
            }
        }
    }

    public function getMonthStartDateAttribute($monthsToSubtract)
    {
        $monthsToSubtract = $monthsToSubtract ?? 0;
        $billingDate = $this->billingDetails->billing_date;

        if ($billingDate == null) {
            return now('')->subMonthsNoOverflow($monthsToSubtract)->startOfMonth();
        }

        if (today('')->day < $billingDate) {
            if (today('')->subMonthsNoOverflow($monthsToSubtract + 1)->addDays($billingDate - today('')->day) > today('')->subMonth()->endOfMonth()) {
                return today('')->subMonthsNoOverflow($monthsToSubtract + 1)->endOfMonth();
            }

            return today('')->subMonthsNoOverflow($monthsToSubtract + 1)->addDays($billingDate - today('')->day);
        }

        if (today('')->day >= $billingDate) {
            return today('')->subMonthsNoOverflow($monthsToSubtract)->startOfMonth()->addDays($billingDate - 1);
        }
    }

    public function getMonthEndDateAttribute($monthsToSubtract)
    {
        $monthsToSubtract = $monthsToSubtract ?? 0;
        $billingDate = $this->billingDetails->billing_date;

        if ($billingDate == null) {
            return now('')->subMonthsNoOverflow($monthsToSubtract)->endOfMonth();
        }

        if (today('')->day < $billingDate) {
            if (today('')->subMonthsNoOverflow($monthsToSubtract)->addDays($billingDate - today('')->day) > today('')->subMonthsNoOverflow($monthsToSubtract)->endOfMonth()) {
                return today('')->subMonthsNoOverflow($monthsToSubtract)->endOfMonth();
            }

            return today('')->subMonthsNoOverflow($monthsToSubtract)->addDays($billingDate - today('')->day - 1);
        }

        if (today('')->subMonthsNoOverflow($monthsToSubtract)->addMonthsNoOverflow()->startOfMonth()->addDays($billingDate - 2) > today('')->addMonthsNoOverflow()->endOfMonth()) {
            return today('')->subMonthsNoOverflow($monthsToSubtract)->addMonthsNoOverflow()->endOfMonth();
        }

        return today('')->subMonthsNoOverflow($monthsToSubtract)->addMonthsNoOverflow()->startOfMonth()->addDays($billingDate - 2);
    }

    public function TeamMembersEffortData()
    {
        $startDate = $this->getMonthStartDateAttribute(1);
        $endDate = $this->getMonthEndDateAttribute(1);

        $data = [];
        $clientId = $this->id;
        $users = User::whereHas('projectTeamMembers.project.client', function ($query) use ($clientId) {
            return $query->where('id', $clientId);
        })->get();

        foreach ($users as $user) {
            $projectTeamMemberForUser = $user->projectTeamMembers()->whereHas('project.client', function ($query) use ($clientId) {
                return $query->where('id', $clientId);
            })->whereHas('project.meta', function ($query) {
                return $query->where([
                    'key' => config('project.meta_keys.billing_level.key'),
                    'value' => config('project.meta_keys.billing_level.value.client.key')
                ]);
            })->get();

            if ($projectTeamMemberForUser->isEmpty()) {
                continue;
            }

            $billableHours = $projectTeamMemberForUser->sum(function ($teamMember) use ($startDate, $endDate) {
                return $teamMember->projectTeamMemberEffort->where('added_on', '>=', $startDate)->where('added_on', '<=', $endDate)->sum('actual_effort');
            });

            if ($billableHours == 0) {
                continue;
            }
            $data[$user->name] = [
                'nickname' => $user->nickname,
                'billableHours' => $billableHours
            ];
        }

        return collect($data);
    }

    public function getCcEmailsAttribute()
    {
        $ccEmails = config('invoice.mail.send-invoice.email') . ',';
        if ($this->secondaryContacts->isNotEmpty()) {
            foreach ($this->secondaryContacts as $secondaryContact) {
                $ccEmails .= $secondaryContact->email . ',';
            }
        }

        return substr_replace($ccEmails, '', -1);
    }

    public function getBccEmailsAttribute()
    {
        $bccEmails = '';

        if ($this->tertiaryContacts->isNotEmpty()) {
            foreach ($this->tertiaryContacts as $tertiaryContact) {
                $bccEmails .= $tertiaryContact->email . ',';
            }
        }

        return substr_replace($bccEmails, '', -1);
    }
}
