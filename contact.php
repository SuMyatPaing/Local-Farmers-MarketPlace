<?php 
require_once __DIR__ . '/security.php'; 
require_once __DIR__ . '/auth.php'; 

if (session_status() !== PHP_SESSION_ACTIVE) { 
    session_start(); 
} 

$pageTitle = 'Contact Us | Local Farmers Marketplace'; 

$currentUser = fm_current_user(); 

$formName = $currentUser !== null 
    ? (string) $currentUser['user_name'] 
    : ''; 

$formEmail = $currentUser !== null 
    ? (string) $currentUser['email'] 
    : ''; 

$formSubject = ''; 
$formMessage = ''; 
$formError = ''; 
$formSuccess = ''; 

if ( 
    !isset($_SESSION['contact_csrf']) || 
    !is_string($_SESSION['contact_csrf']) || 
    $_SESSION['contact_csrf'] === '' 
) { 
    $_SESSION['contact_csrf'] = bin2hex(random_bytes(24)); 
} 

$contactCsrf = $_SESSION['contact_csrf']; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') { 

    $postedToken = isset($_POST['csrf_token']) 
        ? (string) $_POST['csrf_token'] 
        : ''; 

    $formName = isset($_POST['name']) 
        ? trim((string) $_POST['name']) 
        : ''; 

    $formEmail = isset($_POST['email']) 
        ? trim((string) $_POST['email']) 
        : ''; 

    $formSubject = isset($_POST['subject']) 
        ? trim((string) $_POST['subject']) 
        : ''; 

    $formMessage = isset($_POST['message']) 
        ? trim((string) $_POST['message']) 
        : ''; 

    try { 
        if ( 
            !isset($_SESSION['contact_csrf']) || 
            !hash_equals( 
                $_SESSION['contact_csrf'], 
                $postedToken 
            ) 
        ) { 
            throw new Exception( 
                'Your session expired. Please refresh the page and try again.' 
            ); 
        } 

        if ($formName === '') { 
            throw new Exception('Please enter your name.'); 
        } 

        if ( 
            $formEmail === '' || 
            !filter_var($formEmail, FILTER_VALIDATE_EMAIL) 
        ) { 
            throw new Exception('Please enter a valid email address.'); 
        } 

        if ($formSubject === '') { 
            throw new Exception('Please enter a subject.'); 
        } 

        if ($formMessage === '') { 
            throw new Exception('Please enter your message.'); 
        } 

        if (strlen($formName) > 120) { 
            throw new Exception('Name is too long.'); 
        } 

        if (strlen($formEmail) > 190) { 
            throw new Exception('Email address is too long.'); 
        } 

        if (strlen($formSubject) > 180) { 
            throw new Exception('Subject is too long.'); 
        } 

        if (strlen($formMessage) > 5000) { 
            throw new Exception('Message must be 5,000 characters or fewer.'); 
        } 

        $pdo = null; 
        $databaseFile = __DIR__ . '/config/database.php'; 

        if (file_exists($databaseFile)) { 
            require_once $databaseFile; 
        } 

        if (!($pdo instanceof PDO) && function_exists('getPDO')) { 
            $pdo = getPDO(); 
        } 

        if (!($pdo instanceof PDO)) { 
            throw new Exception( 
                'Contact form is temporarily unavailable.' 
            ); 
        } 

        $pdo->setAttribute( 
            PDO::ATTR_ERRMODE, 
            PDO::ERRMODE_EXCEPTION 
        ); 

        $userId = $currentUser !== null && 
            isset($currentUser['user_id']) 
                ? (int) $currentUser['user_id'] 
                : null; 

        $statement = $pdo->prepare( 
            "INSERT INTO contact_messages 
            ( 
                user_id, 
                name, 
                email, 
                subject, 
                message, 
                status, 
                created_at 
            ) 
            VALUES 
            ( 
                :user_id, 
                :name, 
                :email, 
                :subject, 
                :message, 
                'new', 
                NOW() 
            )" 
        ); 

        $statement->execute(array( 
            'user_id' => $userId, 
            'name' => $formName, 
            'email' => $formEmail, 
            'subject' => $formSubject, 
            'message' => $formMessage 
        )); 

        $formSuccess = 
            'Your message has been sent successfully.'; 

        $formSubject = ''; 
        $formMessage = ''; 

        $_SESSION['contact_csrf'] = 
            bin2hex(random_bytes(24)); 

        $contactCsrf = 
            $_SESSION['contact_csrf']; 

    } catch (PDOException $exception) { 
        error_log( 
            'Contact form database error: ' . 
            $exception->getMessage() 
        ); 

        $formError = 
            'The contact form could not save your message. Please try again.'; 
    } catch (Exception $exception) { 
        $formError = $exception->getMessage(); 
    } 
} 

require __DIR__ . '/header.php'; 
?> 

<style>
    /*
    |--------------------------------------------------------------------------
    | Contact page only
    |--------------------------------------------------------------------------
    | Compact centered form so shared responsive rules cannot stretch it.
    | Admin/Vendor are unaffected because this page is market-page-contact only.
    |--------------------------------------------------------------------------
    */
    body.market-public-view.market-page-contact main.contact-page {
        width: 100% !important;
        max-width: 100% !important;
    }

    body.market-public-view.market-page-contact .contact-page-heading {
        width: calc(100% - 32px) !important;
        max-width: 760px !important;
        margin-left: auto !important;
        margin-right: auto !important;
    }

    body.market-public-view.market-page-contact .contact-form-card {
        width: calc(100% - 32px) !important;
        max-width: 760px !important;
        margin-left: auto !important;
        margin-right: auto !important;
    }

    @media (min-width: 1150px) {
        body.market-public-view.market-page-contact main.contact-page {
            padding-top: 34px !important;
            padding-bottom: 54px !important;
        }
    }

    @media (max-width: 640px) {
        body.market-public-view.market-page-contact .contact-page-heading,
        body.market-public-view.market-page-contact .contact-form-card {
            width: calc(100% - 24px) !important;
        }
    }
</style>

<main class="contact-page mx-auto w-full px-4 py-8 sm:px-6"> 

    <section class="contact-page-heading mx-auto max-w-[760px] text-center"> 
        <p class="text-xs font-black uppercase tracking-[0.18em] text-green-600"> 
            Contact Us 
        </p> 

        <h1 class="mt-2 text-3xl font-black text-slate-950 sm:text-[34px]"> 
            How can we help? 
        </h1> 

        <p class="mx-auto mt-3 max-w-2xl text-sm leading-6 text-slate-500 sm:text-[15px]"> 
            Send us your question, feedback or marketplace support request. 
        </p> 
    </section> 

    <section class="contact-form-card mx-auto mt-8 w-full max-w-[760px] rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7"> 

        <?php if ($formSuccess !== ''): ?> 
            <div class="mb-5 flex gap-3 rounded-xl border border-green-200 bg-green-50 p-4"> 

                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-green-600 text-white"> 
                    <i class="fa-solid fa-check"></i> 
                </span> 

                <div> 
                    <p class="text-sm font-black text-green-900"> 
                        Message Sent 
                    </p> 

                    <p class="mt-1 text-xs leading-5 text-green-700"> 
                        <?php echo fm_e($formSuccess); ?> 
                    </p> 
                </div> 
            </div> 
        <?php endif; ?> 

        <?php if ($formError !== ''): ?> 
            <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700"> 
                <?php echo fm_e($formError); ?> 
            </div> 
        <?php endif; ?> 

        <form method="post" action="contact.php"> 

            <input type="hidden" 
                   name="csrf_token" 
                   value="<?php echo fm_e($contactCsrf); ?>"> 

            <div class="grid gap-4 sm:grid-cols-2"> 

                <div> 
                    <label for="name" 
                           class="text-xs font-black text-slate-700"> 
                        Name 
                    </label> 

                    <input type="text" 
                           id="name" 
                           name="name" 
                           maxlength="120" 
                           required 
                           value="<?php echo fm_e($formName); ?>" 
                           class="mt-2 h-10 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition focus:border-green-400 focus:bg-white"> 
                </div> 

                <div> 
                    <label for="email" 
                           class="text-xs font-black text-slate-700"> 
                        Email 
                    </label> 

                    <input type="email" 
                           id="email" 
                           name="email" 
                           maxlength="190" 
                           required 
                           value="<?php echo fm_e($formEmail); ?>" 
                           class="mt-2 h-10 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition focus:border-green-400 focus:bg-white"> 
                </div> 

            </div> 

            <div class="mt-4"> 
                <label for="subject" 
                       class="text-xs font-black text-slate-700"> 
                    Subject 
                </label> 

                <input type="text" 
                       id="subject" 
                       name="subject" 
                       maxlength="180" 
                       required 
                       value="<?php echo fm_e($formSubject); ?>" 
                       class="mt-2 h-10 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition focus:border-green-400 focus:bg-white"> 
            </div> 

            <div class="mt-4"> 
                <label for="message" 
                       class="text-xs font-black text-slate-700"> 
                    Message 
                </label> 

                <textarea id="message" 
                          name="message" 
                          rows="5" 
                          maxlength="5000" 
                          required 
                          class="mt-2 w-full resize-y rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 outline-none transition focus:border-green-400 focus:bg-white"><?php echo fm_e($formMessage); ?></textarea> 
            </div> 

            <button type="submit" 
                    class="mt-5 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-5 text-sm font-black text-white transition hover:bg-green-700"> 
                <i class="fa-solid fa-paper-plane"></i> 
                Send Message 
            </button> 
        </form> 
    </section> 

</main> 

<?php require __DIR__ . '/footer.php'; ?>