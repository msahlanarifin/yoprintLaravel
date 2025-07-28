    <?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration
    {
        /**
         * Run the migrations.
         */
        public function up(): void
        {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->string('unique_key')->unique(); // For idempotency and UPSERT
                $table->string('product_title')->nullable();
                $table->text('product_description')->nullable();
                $table->string('style_number')->nullable(); // Using style_number for STYLE#
                $table->string('sanmar_mainframe_color')->nullable();
                $table->string('size')->nullable();
                $table->string('color_name')->nullable();
                $table->decimal('piece_price', 8, 2)->nullable();
                $table->timestamps();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::dropIfExists('products');
        }
    };
    