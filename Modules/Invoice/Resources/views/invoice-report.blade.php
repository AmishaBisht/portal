@extends('invoice::layouts.master')
@section('content')

<div class="mx-15">
    <br>
    <br>
    <div class="d-flex justify-content-between mb-2">
        <h4 class="mb-1 pb-1">Yearly Invoice Report</h4>
    </div>
    <br>
    <br>
    <div>
        @include('invoice::subviews.invoice-report.filters')
    </div>
    <br>
    <div>
        <table class="table table-bordered table-striped">
            <thead class="thead-light">
                <tr>
                    <th class="sticky-top">S.N</th>
                    <th class="sticky-top">Project Name</th>
                    <th class="sticky-top">Invoice Number</th>
                    <th class="sticky-top">Sent on Date</th>
                    <th class="sticky-top">Payment Date</th>
                    <th class="sticky-top">Invoice Amount</th>
                    @if($clientCurrency == config('constants.countries.india.currency') || $clientCurrency == null)
                        <th class="sticky-top">GST Amount</th> 
                        <th class="sticky-top">TDS</th>
                    @endif
                    @if($clientCurrency != config('constants.countries.india.currency') || $clientCurrency == null)
                        <th class="sticky-top">Amount in INR</th>
                    @endif   
                    <th class="sticky-top">Amount Recieved</th>
                    @if($clientCurrency != config('constants.countries.india.currency') || $clientCurrency == null)
                        <th class="sticky-top">Bank Charges</th>
                        <th class="sticky-top">Dollar Rate</th>
                        <th class="sticky-top">Exchange Rate differene</th>
                        <th class="sticky-top">Amount received in Dollars</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($invoices as $invoice)
                    <tr>
                        <td>{{$loop->index+1}}</td>
                        <td>{{optional($invoice->project)->name ?: ($invoice->client->name . 'Projects')}}</td>
                        <td>{{$invoice->invoice_number}}</td>
                        <td>{{$invoice->sent_on->format(config('invoice.default-date-format'))}}</td>
                        <td>{{$invoice->payment_at ? $invoice->payment_at->format(config('invoice.default-date-format')) : '-'}}</td>
                        <td>{{$invoice->amount}}</td>
                        @if($clientCurrency == config('constants.countries.india.currency') || $clientCurrency == null)
                            <td>{{$invoice->gst}}</td>
                            <td>{{$invoice->tds}}</td>
                        @endif
                        @if($clientCurrency != config('constants.countries.india.currency') || $clientCurrency == null)
                            <td>{{$invoice->InvoiceAmountInInr}}</td>
                        @endif
                        <td>{{$invoice->amount_paid}}</td>
                        @if($clientCurrency != config('constants.countries.india.currency') || $clientCurrency == null)
                            <td>{{$invoice->bank_charges}}</td>
                            <td>{{$invoice->conversion_rate}}</td>
                            <td>{{$invoice->conversion_rate_diff}}</td>
                            <td>{{$invoice->amount_paid}}</td>
                        @endif
                    </tr>
                @endforeach
                <tr>
                    <td>Total</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    @if(!$invoices->isEmpty())
                        <td>{{$invoices->sum('amount')}}</td>
                        @if($clientCurrency == config('constants.countries.india.currency') || $clientCurrency == null)
                            <td>{{$invoices->sum('gst')}}</td>
                            <td>{{$invoices->sum('tds')}}</td>
                        @endif
                        @if($clientCurrency != config('constants.countries.india.currency') || $clientCurrency == null)
                            <td>{{$invoices->sum('InvoiceAmountInInr')}}</td>
                        @endif
                        <td>{{$invoices->sum('amount_paid')}}</td>
                        @if($clientCurrency != config('constants.countries.india.currency') || $clientCurrency == null)
                            <td>{{$invoices->sum('bank_charges')}}</td>
                            <td>{{$invoices->avg('conversion_rate')}}</td>
                            <td>{{$invoices->avg('conversion_rate_diff')}}</td>
                            <td>{{$invoices->sum('amount_paid')}}</td>
                        @endif
                    @else
                        <td>-</td>
                        @if($clientCurrency == config('constants.countries.india.currency') || $clientCurrency == null)
                            <td>-</td>
                            <td>-</td>
                        @endif
                        @if($clientCurrency != config('constants.countries.india.currency') || $clientCurrency == null)
                            <td>-</td>
                        @endif
                        <td>-</td>
                        @if($clientCurrency != config('constants.countries.india.currency') || $clientCurrency == null)
                            <td>-</td>
                            <td>-</td>
                            <td>-</td>
                            <td>-</td>
                        @endif    
                    @endif
                </tr>
            </tbody>
        </table>
    </div>
</div>
@endsection