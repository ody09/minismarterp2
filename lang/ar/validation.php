<?php

return [
    'required' => 'حقل :attribute مطلوب.', 'string' => 'يجب أن يكون :attribute نصًا.',
    'email' => 'يرجى إدخال بريد إلكتروني صالح.', 'unique' => 'قيمة :attribute مستخدمة مسبقًا.',
    'confirmed' => 'تأكيد :attribute غير مطابق.', 'exists' => 'قيمة :attribute غير موجودة.',
    'boolean' => 'قيمة :attribute غير صالحة.', 'integer' => 'يجب أن يكون :attribute عددًا صحيحًا.',
    'array' => 'قيمة :attribute يجب أن تكون قائمة.', 'in' => 'قيمة :attribute غير مسموح بها.',
    'regex' => 'صيغة :attribute غير صالحة.', 'distinct' => 'تحتوي القائمة على قيمة مكررة.',
    'numeric' => 'يجب أن يكون :attribute رقمًا.', 'enum' => 'قيمة :attribute غير مسموح بها.',
    'date_format' => 'يجب أن يكون :attribute تاريخًا صحيحًا بصيغة :format.',
    'after_or_equal' => 'يجب أن يكون :attribute بعد :date أو مساويًا له.',
    'min' => ['string' => 'يجب ألا يقل :attribute عن :min محرفًا.', 'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.'],
    'max' => ['string' => 'يجب ألا يتجاوز :attribute :max محرفًا.', 'array' => 'تجاوزت القائمة الحد المسموح.', 'numeric' => 'يجب ألا تتجاوز قيمة :attribute :max.'],
    'password' => ['mixed' => 'يجب أن تحتوي كلمة المرور على حروف كبيرة وصغيرة.', 'numbers' => 'يجب أن تحتوي كلمة المرور على أرقام.'],
    'attributes' => ['name' => 'الاسم', 'email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور', 'role_id' => 'الدور', 'is_active' => 'حالة الحساب', 'code' => 'الرمز', 'locale' => 'اللغة', 'permissions' => 'الصلاحيات',
        'description' => 'الوصف', 'category_id' => 'التصنيف', 'product_id' => 'المنتج', 'pieces_per_carton' => 'عدد الحبات في الكرتون',
        'purchase_unit' => 'وحدة الشراء', 'selling_unit' => 'وحدة البيع', 'purchase_price' => 'سعر الشراء', 'selling_price' => 'سعر البيع',
        'reorder_level' => 'حد إعادة الطلب', 'batch_number' => 'رقم الدفعة', 'production_date' => 'تاريخ الإنتاج', 'expiration_date' => 'تاريخ الانتهاء',
        'phone' => 'رقم الهاتف', 'address' => 'العنوان', 'location' => 'الموقع', 'q' => 'البحث'],
];
