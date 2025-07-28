<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;

    class Product extends Model
    {
        use HasFactory;

        /**
         * The attributes that are mass assignable.
         *
         * @var array<int, string>
         */
        protected $fillable = [
            'unique_key',
            'product_title',
            'product_description',
            'style_number',
            'sanmar_mainframe_color',
            'size',
            'color_name',
            'piece_price',
        ];
    }
    