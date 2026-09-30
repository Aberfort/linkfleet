import { useEffect, useState } from 'react';
import { Link as RouterLink, Navigate } from 'react-router-dom';
import { toast } from 'react-toastify';
import { Container, Typography, Button, Alert, ToggleButton, ToggleButtonGroup } from '@mui/material';
import { useAuth } from '../contexts/useAuth';
import { useConfig } from '../contexts/useConfig';
import { listPlans } from '../api/billing';
import { errorMessage } from '../api/errors';
import { previewPrices } from '../utils/paddle';
import PlanCards from '../components/PlanCards';
import type { BillingInterval, Plan, PlanList } from '../types';

/**
 * The price list. Public on purpose: people decide before they sign up, and
 * Paddle wants to see prices on the site before it approves a seller.
 */
function PricingPage() {
    const { user } = useAuth();
    const config = useConfig();
    const [catalog, setCatalog] = useState<PlanList | null>(null);
    const [prices, setPrices] = useState<Record<string, string>>({});
    const [interval, setInterval] = useState<BillingInterval>('monthly');

    const billingEnabled = config.billing.enabled;

    useEffect(() => {
        if (!billingEnabled) {
            return;
        }

        listPlans()
            .then(setCatalog)
            .catch((error) => toast.error(errorMessage(error, 'Не вдалося завантажити тарифи.')));
    }, [billingEnabled]);

    useEffect(() => {
        if (!catalog?.checkout_available) {
            return;
        }

        let cancelled = false;

        previewPrices(config.billing, catalog.plans.flatMap((plan) => Object.values(plan.prices)))
            .then((found) => !cancelled && setPrices(found))
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
    }, [catalog, config.billing]);

    if (!config.loaded) {
        return null;
    }

    if (!billingEnabled) {
        return <Navigate to="/" replace />;
    }

    const hasYearly = catalog?.plans.some((plan) => plan.prices.yearly) ?? false;

    const action = (plan: Plan) => {
        const free = Object.keys(plan.prices).length === 0;

        if (user) {
            return (
                <Button component={RouterLink} to="/workspaces" variant={free ? 'outlined' : 'contained'}>
                    {free ? 'Мої workspaces' : `Обрати ${plan.name}`}
                </Button>
            );
        }

        return (
            <Button
                component={RouterLink}
                to={config.registrationEnabled ? '/register' : '/login'}
                variant={free ? 'outlined' : 'contained'}
            >
                {free ? 'Почати безкоштовно' : `Обрати ${plan.name}`}
            </Button>
        );
    };

    return (
        <Container maxWidth="lg" sx={{ mt: 4, mb: 6 }}>
            <Typography variant="h4" gutterBottom>
                Тарифи
            </Typography>
            <Typography color="text.secondary" sx={{ mb: 3, maxWidth: '65ch' }}>
                Усі функції — на кожному тарифі. Тариф визначає лише, скільки посилань, власних доменів і учасників
                може мати workspace. LinkFleet також відкритий і працює на вашому сервері без жодних лімітів.
            </Typography>

            {catalog && !catalog.checkout_available && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Оплата ще не відкрита — тарифи показано для ознайомлення.
                </Alert>
            )}

            {hasYearly && (
                <ToggleButtonGroup
                    exclusive
                    size="small"
                    value={interval}
                    onChange={(_, next: BillingInterval | null) => next && setInterval(next)}
                    aria-label="Період оплати"
                    sx={{ mb: 2 }}
                >
                    <ToggleButton value="monthly">Щомісяця</ToggleButton>
                    <ToggleButton value="yearly">Щороку</ToggleButton>
                </ToggleButtonGroup>
            )}

            {catalog ? (
                <PlanCards plans={catalog.plans} interval={interval} prices={prices} action={(plan) => action(plan)} />
            ) : (
                <Typography>Завантаження...</Typography>
            )}

            {catalog && (
                <Typography variant="body2" color="text.secondary" sx={{ mt: 3 }}>
                    Оплату приймає Paddle — він же рахує та сплачує ПДВ, тож ціна на касі остаточна. Ліміт
                    обмежує лише додавання нового: коли його вичерпано, наявні посилання далі працюють.
                </Typography>
            )}
        </Container>
    );
}

export default PricingPage;
