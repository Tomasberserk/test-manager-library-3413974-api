<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure schema library exists
        DB::statement('CREATE SCHEMA IF NOT EXISTS library;');

        // 1. category
        DB::statement('
            CREATE TABLE IF NOT EXISTS library.category (
                id UUID PRIMARY KEY,
                name VARCHAR(120) NOT NULL UNIQUE
            );
        ');

        // 2. user
        DB::statement('
            CREATE TABLE IF NOT EXISTS library."user" (
                id UUID PRIMARY KEY,
                username VARCHAR(120) NOT NULL UNIQUE,
                password_hash VARCHAR(512) NOT NULL,
                role VARCHAR(40) NOT NULL,
                CONSTRAINT ck_user_role CHECK (role IN (\'admin\', \'librarian\'))
            );
        ');

        // 3. member
        DB::statement('
            CREATE TABLE IF NOT EXISTS library.member (
                id UUID PRIMARY KEY,
                full_name VARCHAR(200) NOT NULL,
                document_number VARCHAR(30) NOT NULL UNIQUE
            );
        ');

        // 4. book
        DB::statement('
            CREATE TABLE IF NOT EXISTS library.book (
                id UUID PRIMARY KEY,
                title VARCHAR(250) NOT NULL,
                author VARCHAR(200) NOT NULL,
                isbn CHAR(13) NOT NULL,
                category_id UUID NOT NULL REFERENCES library.category(id) ON DELETE RESTRICT,
                total_copies INTEGER NOT NULL,
                available_copies INTEGER NOT NULL,
                cover_key VARCHAR(512) NULL,
                deleted_at TIMESTAMPTZ NULL,
                CONSTRAINT ck_book_available_range CHECK (0 <= available_copies AND available_copies <= total_copies),
                CONSTRAINT ck_book_total_positive CHECK (total_copies >= 1)
            );
        ');

        // Partial unique index for active ISBNs and search index
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS idx_book_active_isbn ON library.book (isbn) WHERE deleted_at IS NULL;');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_book_category_title ON library.book (category_id, title) WHERE deleted_at IS NULL;');

        // 5. loan
        DB::statement('
            CREATE TABLE IF NOT EXISTS library.loan (
                id UUID PRIMARY KEY,
                member_id UUID NOT NULL REFERENCES library.member(id) ON DELETE RESTRICT,
                loaned_by_user_id UUID NOT NULL REFERENCES library."user"(id) ON DELETE RESTRICT,
                loaned_at TIMESTAMPTZ NOT NULL
            );
        ');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_loan_loaned_at ON library.loan (loaned_at);');

        // 6. loan_item
        DB::statement('
            CREATE TABLE IF NOT EXISTS library.loan_item (
                id UUID PRIMARY KEY,
                loan_id UUID NOT NULL REFERENCES library.loan(id) ON DELETE CASCADE,
                book_id UUID NOT NULL REFERENCES library.book(id) ON DELETE RESTRICT,
                book_title VARCHAR(250) NOT NULL,
                isbn CHAR(13) NOT NULL,
                category_name VARCHAR(120) NOT NULL,
                due_at TIMESTAMPTZ NOT NULL,
                returned_at TIMESTAMPTZ NULL,
                CONSTRAINT uq_loan_book UNIQUE (loan_id, book_id)
            );
        ');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_loan_item_book_id ON library.loan_item (book_id);');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_loan_item_due_unreturned ON library.loan_item (due_at) WHERE returned_at IS NULL;');

        // Seed 5 categories from specification (§9)
        $categories = [
            ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Novela'],
            ['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Ciencia'],
            ['id' => '33333333-3333-4333-8333-333333333333', 'name' => 'Tecnología'],
            ['id' => '44444444-4444-4444-8444-444444444444', 'name' => 'Historia'],
            ['id' => '55555555-5555-4555-8555-555555555555', 'name' => 'Infantil'],
        ];

        foreach ($categories as $cat) {
            DB::statement('
                INSERT INTO library.category (id, name)
                VALUES (?, ?)
                ON CONFLICT (id) DO NOTHING;
            ', [$cat['id'], $cat['name']]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS library.loan_item CASCADE;');
        DB::statement('DROP TABLE IF EXISTS library.loan CASCADE;');
        DB::statement('DROP TABLE IF EXISTS library.book CASCADE;');
        DB::statement('DROP TABLE IF EXISTS library.member CASCADE;');
        DB::statement('DROP TABLE IF EXISTS library."user" CASCADE;');
        DB::statement('DROP TABLE IF EXISTS library.category CASCADE;');
    }
};
