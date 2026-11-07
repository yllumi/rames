<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Billing\InsufficientCredits;
use app\library\Branch\BranchChangeBlocked;
use app\library\Branch\BranchChangeService;
use support\Request;

/**
 * HTTP mediator for Git branch switch-and-deploy.
 */
class BranchController extends AppControllerBase
{
    public function change(Request $request, string $id)
    {
        $app = $this->findApp($id, 'deploy');

        try {
            $result = $this->branchChangeService()->change(
                $app,
                $request->post('branch'),
                function () use ($app): void {
                    $this->billingAssertCanStart($app, current_user());
                },
                $this->currentActorId()
            );
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        } catch (BranchChangeBlocked $e) {
            $payload = ['code' => 1, 'error' => $e->getMessage()];
            if ($e->busy()) {
                $payload['busy'] = true;
            }
            if ($request->expectsJson()) {
                return json($payload);
            }

            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        if ($request->expectsJson()) {
            return json([
                'code' => 0,
                'status' => $result['status'],
                'stage' => $result['stage'],
                'message' => $result['message'],
            ]);
        }

        flash_set('success', $result['message']);

        return redirect('/apps/' . $id);
    }

    protected function branchChangeService(): BranchChangeService
    {
        return new BranchChangeService();
    }
}
