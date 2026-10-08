<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Markets
        |--------------------------------------------------------------------------
        */

        Schema::create('markets', function (Blueprint $table) {
            $table->id();

            // Непредсказуемый публичный идентификатор для URL.
            $table->ulid('public_id')->unique();

            // ID рынка в основном wrangle.win.
            $table->unsignedBigInteger('wrangle_market_id')->unique();

            $table->string('status', 32)->index();
            $table->string('source_locale', 10)->nullable();

            $table->timestamp('finish_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            /*
             * Заполнявается после создания outcomes.
             * FK здесь специально пока не создаём, поскольку
             * market_outcomes ещё не существует.
             */
            $table->unsignedBigInteger('winning_outcome_id')->nullable()->index();

            /*
             * Финальные значения пула фиксируем при resolution.
             *
             * winning_pool       = сумма ставок на победителя
             * losing_pool        = сумма остальных ставок
             * profit_per_dollar  = losing_pool / winning_pool
             * return_per_dollar  = 1 + profit_per_dollar
             */
            $table->decimal('winning_pool', 18, 2)->nullable();
            $table->decimal('losing_pool', 18, 2)->nullable();
            $table->decimal('profit_per_dollar', 18, 8)->nullable();
            $table->decimal('return_per_dollar', 18, 8)->nullable();

            $table->timestamps();

            $table->index(['status', 'finish_at']);
        });

        /*
        |--------------------------------------------------------------------------
        | Market translations
        |--------------------------------------------------------------------------
        */

        Schema::create('market_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            $table->string('locale', 10);
            $table->string('title', 1000);
            $table->text('description')->nullable();

            $table->timestamps();

            $table->unique(['market_id', 'locale']);
            $table->index(['locale', 'market_id']);
        });

        /*
        |--------------------------------------------------------------------------
        | Outcomes
        |--------------------------------------------------------------------------
        */

        Schema::create('market_outcomes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            // Answer ID из wrangle.win.
            $table->unsignedBigInteger('wrangle_answer_id');

            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('is_winner')->default(false);

            $table->timestamps();

            $table->unique(['market_id', 'wrangle_answer_id']);
            $table->index(['market_id', 'sort_order']);
        });

        /*
         * Теперь market_outcomes существует — можно добавить FK победителя.
         */
        Schema::table('markets', function (Blueprint $table) {
            $table->foreign('winning_outcome_id')
                ->references('id')
                ->on('market_outcomes')
                ->nullOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | Outcome translations
        |--------------------------------------------------------------------------
        */

        Schema::create('outcome_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_outcome_id')
                ->constrained('market_outcomes')
                ->cascadeOnDelete();

            $table->string('locale', 10);
            $table->string('title', 1000);

            $table->timestamps();

            $table->unique(['market_outcome_id', 'locale']);
        });

        /*
        |--------------------------------------------------------------------------
        | Categories
        |--------------------------------------------------------------------------
        */

        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            // ID категории в wrangle.win.
            $table->unsignedBigInteger('wrangle_category_id')->unique();

            $table->string('name');
            $table->string('slug')->nullable()->index();

            $table->timestamps();
        });

        Schema::create('category_market', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            $table->primary(['category_id', 'market_id']);
        });

        /*
        |--------------------------------------------------------------------------
        | Countries
        |--------------------------------------------------------------------------
        */

        Schema::create('countries', function (Blueprint $table) {
            $table->id();

            // ID страны в wrangle.win.
            $table->unsignedBigInteger('wrangle_country_id')->unique();

            $table->string('code', 10)->nullable()->index();
            $table->string('name');

            $table->timestamps();
        });

        Schema::create('country_market', function (Blueprint $table) {
            $table->foreignId('country_id')
                ->constrained('countries')
                ->cascadeOnDelete();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            $table->primary(['country_id', 'market_id']);
        });

        /*
        |--------------------------------------------------------------------------
        | Country groups
        |--------------------------------------------------------------------------
        */

        Schema::create('country_groups', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('wrangle_country_group_id')->unique();

            $table->string('name');
            $table->string('slug')->nullable()->index();

            $table->timestamps();
        });

        Schema::create('country_group_market', function (Blueprint $table) {
            $table->foreignId('country_group_id')
                ->constrained('country_groups')
                ->cascadeOnDelete();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            $table->primary(['country_group_id', 'market_id']);
        });

        /*
        |--------------------------------------------------------------------------
        | Sources
        |--------------------------------------------------------------------------
        */

        Schema::create('market_sources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            $table->text('url');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['market_id', 'sort_order']);
        });

        /*
        |--------------------------------------------------------------------------
        | Virtual bets
        |--------------------------------------------------------------------------
        |
        | Никакой связи с реальными ставками wrangle.win.
        | Это исключительно локальная виртуальная экономика example.
        |--------------------------------------------------------------------------
        */

        Schema::create('virtual_bets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->cascadeOnDelete();

            $table->foreignId('market_outcome_id')
                ->constrained('market_outcomes')
                ->restrictOnDelete();

            // Для анонимного visitor без регистрации.
            // Позже будем хранить случайный visitor ID в cookie/session.
            $table->ulid('visitor_id')->nullable()->index();

            $table->decimal('amount', 12, 2);

            /*
             * placed    - ставка сделана
             * won       - выиграла
             * lost      - проиграла
             * refunded  - market cancelled
             */
            $table->string('status', 20)->default('placed')->index();

            // Чистая прибыль.
            $table->decimal('profit', 18, 2)->nullable();

            // Полная выплата = stake + profit.
            $table->decimal('payout', 18, 2)->nullable();

            $table->timestamps();

            $table->index(['market_id', 'market_outcome_id']);
            $table->index(['market_id', 'status']);
            $table->index(['visitor_id', 'created_at']);
        });

        /*
        |--------------------------------------------------------------------------
        | Incoming webhook event log
        |--------------------------------------------------------------------------
        |
        | Используется для:
        | - аудита
        | - idempotency
        | - обнаружения повторной доставки
        | - расследования проблем синхронизации
        |--------------------------------------------------------------------------
        */

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();

            // Например market.published / market.resolved.
            $table->string('event', 64)->index();

            $table->unsignedBigInteger('wrangle_market_id')->nullable()->index();

            /*
             * SHA-256 оригинального payload.
             * Повтор одного и того же события не должен обрабатываться дважды.
             */
            $table->char('payload_hash', 64)->unique();

            $table->json('payload');

            /*
             * pending / processed / failed / ignored
             */
            $table->string('status', 20)->default('pending')->index();

            $table->text('error')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index([
                'wrangle_market_id',
                'event',
            ]);
        });
    }

    public function down(): void
    {
        /*
         * Сначала удаляем FK markets -> market_outcomes,
         * иначе получаем циклическую зависимость.
         */
        Schema::table('markets', function (Blueprint $table) {
            $table->dropForeign(['winning_outcome_id']);
        });

        Schema::dropIfExists('webhook_events');

        Schema::dropIfExists('virtual_bets');

        Schema::dropIfExists('market_sources');

        Schema::dropIfExists('country_group_market');
        Schema::dropIfExists('country_groups');

        Schema::dropIfExists('country_market');
        Schema::dropIfExists('countries');

        Schema::dropIfExists('category_market');
        Schema::dropIfExists('categories');

        Schema::dropIfExists('outcome_translations');
        Schema::dropIfExists('market_outcomes');

        Schema::dropIfExists('market_translations');
        Schema::dropIfExists('markets');
    }
};
