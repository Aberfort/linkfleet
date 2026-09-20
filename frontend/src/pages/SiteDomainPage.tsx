import { useCallback, useEffect, useState } from 'react';
import { Link as RouterLink, useParams } from 'react-router-dom';
import { Formik, Form } from 'formik';
import * as Yup from 'yup';
import { toast } from 'react-toastify';
import {
    Container,
    Typography,
    Box,
    Paper,
    Button,
    TextField,
    Alert,
    AlertTitle,
    Chip,
    Breadcrumbs,
    Link,
    Table,
    TableBody,
    TableRow,
    TableCell,
    IconButton,
    Tooltip,
    Stack,
} from '@mui/material';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import { useAuth } from '../contexts/useAuth';
import { getSite } from '../api/sites';
import { getDomain, attachDomain, verifyDomain, checkDomain, detachDomain } from '../api/domains';
import { fetchAppConfig } from '../api/config';
import { errorMessage, validationErrors } from '../api/errors';
import { publicBaseUrl } from '../api/client';
import type { Domain, DomainCheck, Site } from '../types';

const shortLinkHost = new URL(publicBaseUrl).host;

const validationSchema = Yup.object({
    host: Yup.string().required("Домен є обов'язковим"),
});

function CopyableRow({ label, value }: { label: string; value: string }) {
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            toast.success('Скопійовано.');
        } catch {
            toast.error('Не вдалося скопіювати.');
        }
    };

    return (
        <TableRow>
            <TableCell sx={{ width: 120, color: 'text.secondary', border: 0, pl: 0 }}>{label}</TableCell>
            <TableCell sx={{ border: 0 }}>
                <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                    <code style={{ wordBreak: 'break-all' }}>{value}</code>
                    <Tooltip title="Копіювати">
                        <IconButton size="small" onClick={copy} aria-label={`Копіювати ${label}`}>
                            <ContentCopyIcon fontSize="inherit" />
                        </IconButton>
                    </Tooltip>
                </Box>
            </TableCell>
        </TableRow>
    );
}

function SiteDomainPage() {
    const { siteId } = useParams<{ siteId: string }>();
    const { user } = useAuth();
    const id = Number(siteId);
    const isDemo = Boolean(user?.is_demo);

    const [site, setSite] = useState<Site | null>(null);
    const [domain, setDomain] = useState<Domain | null>(null);
    const [loading, setLoading] = useState(true);
    const [verifying, setVerifying] = useState(false);
    const [target, setTarget] = useState(shortLinkHost); // until /api/config says where this deployment wants domains pointed
    const [check, setCheck] = useState<DomainCheck | null>(null);
    const [checking, setChecking] = useState(false);

    const fetchData = useCallback(async () => {
        setLoading(true);
        try {
            const [siteData, domainData, config] = await Promise.all([
                getSite(id),
                getDomain(id),
                // The target is a nicety - never let it block the page.
                fetchAppConfig().catch(() => null),
            ]);
            setSite(siteData);
            setDomain(domainData);
            if (config?.custom_domain_target) {
                setTarget(config.custom_domain_target);
            }
        } catch (error) {
            toast.error(errorMessage(error, 'Помилка при завантаженні домену.'));
        } finally {
            setLoading(false);
        }
    }, [id]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const handleVerify = async () => {
        if (!domain) {
            return;
        }
        setVerifying(true);
        try {
            setDomain(await verifyDomain(domain.id));
            toast.success('Домен підтверджено.');
        } catch (error) {
            toast.error(errorMessage(error, 'Не вдалося підтвердити домен.'));
        } finally {
            setVerifying(false);
        }
    };

    const handleCheck = async () => {
        if (!domain) {
            return;
        }
        setChecking(true);
        try {
            setCheck(await checkDomain(domain.id));
        } catch (error) {
            toast.error(errorMessage(error, 'Не вдалося перевірити підключення.'));
        } finally {
            setChecking(false);
        }
    };

    const handleDetach = async () => {
        if (!domain || !window.confirm(`Відключити ${domain.host}? Посилання на ньому перестануть відкриватись.`)) {
            return;
        }
        try {
            await detachDomain(domain.id);
            setDomain(null);
            setCheck(null);
            toast.success('Домен відключено.');
        } catch (error) {
            toast.error(errorMessage(error, 'Помилка при відключенні домену.'));
        }
    };

    if (loading) {
        return (
            <Container maxWidth="md" sx={{ mt: 4 }}>
                <Typography>Завантаження...</Typography>
            </Container>
        );
    }

    return (
        <Container maxWidth="md" sx={{ mt: 4, mb: 6 }}>
            <Breadcrumbs sx={{ mb: 2 }}>
                <Link component={RouterLink} to="/sites" underline="hover">
                    Сайти
                </Link>
                <Typography color="text.primary">{site?.name ?? '...'}</Typography>
            </Breadcrumbs>

            <Typography variant="h4" gutterBottom>
                Власний домен
            </Typography>
            <Typography color="text.secondary" sx={{ mb: 3 }}>
                Замість {shortLinkHost}/r/код короткі посилання цього сайту зможуть
                відкриватись на твоєму домені: go.example.com/код.
            </Typography>

            {isDemo && (
                <Alert severity="info" sx={{ mb: 3 }}>
                    Демо-акаунт лише для читання — підключити домен не можна.
                </Alert>
            )}

            {!domain && (
                <Paper sx={{ p: 3 }}>
                    <Formik
                        initialValues={{ host: '' }}
                        validationSchema={validationSchema}
                        onSubmit={async (values, { setErrors, setSubmitting }) => {
                            try {
                                setDomain(await attachDomain(id, values.host));
                                toast.success('Домен додано. Лишилось підтвердити володіння.');
                            } catch (error) {
                                setErrors(validationErrors(error));
                                toast.error(errorMessage(error, 'Не вдалося додати домен.'));
                            } finally {
                                setSubmitting(false);
                            }
                        }}
                    >
                        {({ isSubmitting, errors, touched, values, handleChange }) => (
                            <Form>
                                <TextField
                                    fullWidth
                                    label="Домен"
                                    name="host"
                                    placeholder="go.example.com"
                                    value={values.host}
                                    onChange={handleChange}
                                    error={touched.host && Boolean(errors.host)}
                                    helperText={
                                        (touched.host && errors.host) ||
                                        'Піддомен, який ти контролюєш. Без протоколу й шляху.'
                                    }
                                    disabled={isDemo}
                                />
                                <Button
                                    type="submit"
                                    variant="contained"
                                    sx={{ mt: 2 }}
                                    disabled={isSubmitting || isDemo}
                                >
                                    Додати домен
                                </Button>
                            </Form>
                        )}
                    </Formik>
                </Paper>
            )}

            {domain && (
                <Paper sx={{ p: 3 }}>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, mb: 2, flexWrap: 'wrap' }}>
                        <Typography variant="h6" component="span">
                            {domain.host}
                        </Typography>
                        <Chip
                            size="small"
                            label={domain.is_verified ? 'Підтверджено' : 'Очікує підтвердження'}
                            color={domain.is_verified ? 'success' : 'warning'}
                        />
                    </Box>

                    {!domain.is_verified && (
                        <>
                            <Typography sx={{ mb: 2 }}>
                                Додай цей TXT-запис у DNS свого домену, а тоді натисни «Перевірити».
                                Оновлення DNS може зайняти до кількох годин.
                            </Typography>
                            <Table size="small" sx={{ mb: 2 }}>
                                <TableBody>
                                    <CopyableRow label="Тип" value="TXT" />
                                    <CopyableRow label="Ім'я" value={domain.txt_record_name} />
                                    <CopyableRow label="Значення" value={domain.verification_token} />
                                </TableBody>
                            </Table>
                        </>
                    )}

                    {domain.is_verified && (
                        <>
                            <Typography sx={{ mb: 2 }}>
                                Володіння підтверджено. Лишилось направити домен на цей застосунок —
                                додай запис у DNS, а тоді перевір підключення.
                            </Typography>
                            <Table size="small" sx={{ mb: 1 }}>
                                <TableBody>
                                    <CopyableRow label="Тип" value="CNAME" />
                                    <CopyableRow label="Ім'я" value={domain.host} />
                                    <CopyableRow label="Значення" value={target} />
                                </TableBody>
                            </Table>
                            {domain.host.split('.').length === 2 && (
                                <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                                    Кореневий домен не може мати CNAME у більшості DNS-провайдерів — використай
                                    ALIAS/ANAME або A-запис на ті самі адреси.
                                </Typography>
                            )}

                            {check && (
                                <Alert
                                    severity={check.https ? 'success' : 'warning'}
                                    sx={{ my: 2 }}
                                    icon={false}
                                >
                                    <AlertTitle>
                                        {check.https
                                            ? 'Домен працює'
                                            : check.dns
                                              ? 'DNS уже вказує сюди, сертифікат ще готується'
                                              : `DNS ще не вказує на ${check.target}`}
                                    </AlertTitle>
                                    {check.https
                                        ? `Посилання цього сайту відкриваються на https://${domain.host}/код.`
                                        : check.dns
                                          ? 'Зазвичай це до кількох хвилин — перевір ще раз трохи згодом.'
                                          : 'Зміни в DNS можуть поширюватись до кількох годин.'}
                                    <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
                                        <Chip
                                            size="small"
                                            label="DNS"
                                            color={check.dns ? 'success' : 'default'}
                                            variant={check.dns ? 'filled' : 'outlined'}
                                        />
                                        <Chip
                                            size="small"
                                            label="HTTPS"
                                            color={check.https ? 'success' : 'default'}
                                            variant={check.https ? 'filled' : 'outlined'}
                                        />
                                    </Stack>
                                </Alert>
                            )}
                        </>
                    )}

                    <Stack direction="row" spacing={1}>
                        {!domain.is_verified && (
                            <Button variant="contained" onClick={handleVerify} disabled={verifying || isDemo}>
                                {verifying ? 'Перевіряю...' : 'Перевірити'}
                            </Button>
                        )}
                        {domain.is_verified && (
                            <Button variant="contained" onClick={handleCheck} disabled={checking}>
                                {checking ? 'Перевіряю...' : 'Перевірити підключення'}
                            </Button>
                        )}
                        <Button color="error" onClick={handleDetach} disabled={isDemo}>
                            Відключити
                        </Button>
                    </Stack>
                </Paper>
            )}
        </Container>
    );
}

export default SiteDomainPage;
