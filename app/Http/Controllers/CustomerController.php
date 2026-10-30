<?php

namespace App\Http\Controllers;

use App\Models\Customer;

class CustomerController extends ContactController
{
    protected string $model = Customer::class;

    protected string $resource = 'customers';
}
