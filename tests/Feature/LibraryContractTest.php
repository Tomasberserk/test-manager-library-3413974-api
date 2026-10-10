<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Category;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Member;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LibraryContractTest extends TestCase
{
    private string $adminToken;
    private string $librarianToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('library:init');

        // Login as admin
        $res = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'admin1234',
        ]);
        $this->adminToken = $res->json('access_token');
    }

    public function test_t01_health_endpoint(): void
    {
        $response = $this->getJson('/health');
        $response->assertStatus(200)
            ->assertExactJson(['status' => 'ok']);

        $responseV1 = $this->getJson('/api/v1/health');
        $responseV1->assertStatus(200)
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_t06_authentication_contract(): void
    {
        // 1. Valid admin login
        $res = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'admin1234',
        ]);
        $res->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'role'])
            ->assertJson([
                'token_type' => 'Bearer',
                'expires_in' => 28800,
                'role' => 'admin',
            ]);

        // 2. Invalid password does NOT disclose whether user or password failed (CA-01.2)
        $resInvalid = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'wrongpass',
        ]);
        $resInvalid->assertStatus(401)
            ->assertJson([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Credenciales inválidas.',
            ]);

        // 3. Invalid username
        $resNonExistent = $this->postJson('/api/v1/auth/login', [
            'username' => 'nonexistent_user',
            'password' => 'randompass',
        ]);
        $resNonExistent->assertStatus(401)
            ->assertJson([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Credenciales inválidas.',
            ]);

        // 4. Missing token on protected endpoint gives 401
        $resProtected = $this->getJson('/api/v1/categories');
        $resProtected->assertStatus(401)
            ->assertJson(['code' => 'INVALID_CREDENTIALS']);
    }

    public function test_hu02_create_librarian_and_role_security(): void
    {
        // Attempting to create admin role via API responds 422 (CA-02.3)
        $resAdminAttempt = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/users', [
                'username' => 'superadmin',
                'password' => 'secret123',
                'role' => 'admin',
            ]);
        $resAdminAttempt->assertStatus(422)
            ->assertJson(['code' => 'VALIDATION_ERROR']);

        // Create librarian (trimmed, lowercase) (CA-02.1, CA-02.2)
        $librarianUsername = 'librarian_' . uniqid();
        $resLibrarian = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/users', [
                'username' => "  {$librarianUsername}  ",
                'password' => 'libpassword123',
                'role' => 'librarian',
            ]);
        $resLibrarian->assertStatus(201)
            ->assertJson([
                'username' => strtolower($librarianUsername),
                'role' => 'librarian',
            ])
            ->assertJsonMissing(['password', 'password_hash']);

        // Duplicate username responds 409 USERNAME_TAKEN
        $resDup = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/users', [
                'username' => $librarianUsername,
                'password' => 'anotherpass',
                'role' => 'librarian',
            ]);
        $resDup->assertStatus(409)
            ->assertJson(['code' => 'USERNAME_TAKEN']);
    }

    public function test_t02_categories_seeded(): void
    {
        $res = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/categories');

        $res->assertStatus(200);
        $this->assertCount(5, $res->json());

        $names = array_column($res->json(), 'name');
        $this->assertContains('Novela', $names);
        $this->assertContains('Ciencia', $names);
        $this->assertContains('Tecnología', $names);
        $this->assertContains('Historia', $names);
        $this->assertContains('Infantil', $names);
    }

    public function test_t08_book_crud_and_invariants(): void
    {
        $cat = Category::first();
        $isbn = '978' . str_pad((string) rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);

        // 1. Create book
        $resCreate = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/books', [
                'title' => 'Cien años de soledad',
                'author' => 'Gabriel García Márquez',
                'isbn' => $isbn,
                'category_id' => (string) $cat->id,
                'total_copies' => 3,
            ]);

        $resCreate->assertStatus(201)
            ->assertJson([
                'title' => 'Cien años de soledad',
                'author' => 'Gabriel García Márquez',
                'isbn' => $isbn,
                'total_copies' => 3,
                'available_copies' => 3,
                'cover_key' => null,
            ]);

        $bookId = $resCreate->json('id');

        // 2. Duplicate active ISBN responds 409 ISBN_TAKEN
        $resDupIsbn = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/books', [
                'title' => 'Otro libro',
                'author' => 'Otro autor',
                'isbn' => $isbn,
                'category_id' => (string) $cat->id,
                'total_copies' => 2,
            ]);
        $resDupIsbn->assertStatus(409)
            ->assertJson(['code' => 'ISBN_TAKEN']);

        // 3. Search books
        $resSearch = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/books?q=soledad&page=1&size=10");
        $resSearch->assertStatus(200)
            ->assertJsonStructure(['items', 'page', 'size', 'total']);
        $this->assertGreaterThanOrEqual(1, $resSearch->json('total'));

        // 4. Update book
        $resUpdate = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->putJson("/api/v1/books/{$bookId}", [
                'title' => 'Cien años de soledad (Edición Ilustrada)',
                'author' => 'Gabriel García Márquez',
                'category_id' => (string) $cat->id,
                'total_copies' => 5,
            ]);
        $resUpdate->assertStatus(200)
            ->assertJson([
                'title' => 'Cien años de soledad (Edición Ilustrada)',
                'total_copies' => 5,
                'available_copies' => 5,
            ]);
    }

    public function test_t09_t10_loans_returns_and_frozen_report(): void
    {
        $cat = Category::first();
        $isbn1 = '978' . str_pad((string) rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
        $isbn2 = '978' . str_pad((string) rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);

        $book1 = Book::create([
            'title' => 'El Quijote',
            'author' => 'Miguel de Cervantes',
            'isbn' => $isbn1,
            'category_id' => $cat->id,
            'total_copies' => 2,
            'available_copies' => 2,
        ]);

        $book2 = Book::create([
            'title' => 'Ficciones',
            'author' => 'Jorge Luis Borges',
            'isbn' => $isbn2,
            'category_id' => $cat->id,
            'total_copies' => 1,
            'available_copies' => 1,
        ]);

        $docNum = 'DOC_' . uniqid();
        $member = Member::create([
            'full_name' => 'Carlos Mendoza',
            'document_number' => $docNum,
        ]);

        // 1. Duplicate book in loan responds 422 DUPLICATE_BOOK_IN_LOAN (CA-06.2)
        $resDupBook = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/loans', [
                'member_id' => (string) $member->id,
                'items' => [
                    ['book_id' => (string) $book1->id],
                    ['book_id' => (string) $book1->id],
                ],
            ]);
        $resDupBook->assertStatus(422)
            ->assertJson(['code' => 'DUPLICATE_BOOK_IN_LOAN']);

        // 2. Valid Loan of 2 books
        $resLoan = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/loans', [
                'member_id' => (string) $member->id,
                'items' => [
                    ['book_id' => (string) $book1->id],
                    ['book_id' => (string) $book2->id],
                ],
            ]);

        $resLoan->assertStatus(201)
            ->assertJson([
                'member_id' => (string) $member->id,
                'status' => 'open',
            ]);
        $this->assertCount(2, $resLoan->json('items'));

        $loanId = $resLoan->json('id');
        $item1Id = $resLoan->json('items.0.id');
        $item2Id = $resLoan->json('items.1.id');

        // Check stock was deducted
        $book1Fresh = Book::find($book1->id);
        $book2Fresh = Book::find($book2->id);
        $this->assertEquals(1, $book1Fresh->available_copies);
        $this->assertEquals(0, $book2Fresh->available_copies);

        // 3. Try to borrow book2 when available_copies = 0 -> 409 BOOK_NOT_AVAILABLE
        $anotherMember = Member::create([
            'full_name' => 'Elena Gomez',
            'document_number' => 'DOC_' . uniqid(),
        ]);
        $resNoStock = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/v1/loans', [
                'member_id' => (string) $anotherMember->id,
                'items' => [
                    ['book_id' => (string) $book2->id],
                ],
            ]);
        $resNoStock->assertStatus(409)
            ->assertJson(['code' => 'BOOK_NOT_AVAILABLE']);

        // 4. Return line item1 (CA-07.1)
        $resReturn = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/loans/{$loanId}/items/{$item1Id}/return");
        $resReturn->assertStatus(200)
            ->assertJsonStructure(['id', 'returned_at']);

        // Book 1 stock restored to 2
        $this->assertEquals(2, Book::find($book1->id)->available_copies);

        // 5. Try to return item1 again -> 409 ITEM_ALREADY_RETURNED (CA-07.2)
        $resDupReturn = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/v1/loans/{$loanId}/items/{$item1Id}/return");
        $resDupReturn->assertStatus(409)
            ->assertJson(['code' => 'ITEM_ALREADY_RETURNED']);

        // 6. Test Frozen Report (ADR-004)
        // Rename book1 in active catalog
        $book1->title = 'Don Quijote de la Mancha (Título Modificado)';
        $book1->save();

        $from = Carbon::now('UTC')->subDay()->toDateString();
        $to = Carbon::now('UTC')->addDay()->toDateString();

        $resReport = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/most-borrowed?from={$from}&to={$to}");

        $resReport->assertStatus(200);
        $rows = $resReport->json('rows');

        // Check that frozen report retains original title 'El Quijote'
        $quijoteRow = collect($rows)->firstWhere('book_id', (string) $book1->id);
        $this->assertNotNull($quijoteRow);
        $this->assertEquals('El Quijote', $quijoteRow['book_title']);

        // 7. Inverted date range gives 422 INVALID_DATE_RANGE (CA-10.2)
        $resInvDate = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/most-borrowed?from=2026-10-20&to=2026-10-10");
        $resInvDate->assertStatus(422)
            ->assertJson(['code' => 'INVALID_DATE_RANGE']);
    }
}
