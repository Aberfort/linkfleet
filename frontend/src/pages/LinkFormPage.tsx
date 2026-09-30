import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Formik, Form } from 'formik';
import * as Yup from 'yup';
import { toast } from 'react-toastify';
import {
    Container,
    TextField,
    Button,
    Typography,
    Box,
    Alert,
    FormControlLabel,
    Checkbox,
    Divider,
} from '@mui/material';
import { getLink, createLink, updateLink } from '../api/links';
import { errorMessage, validationErrors } from '../api/errors';
import UtmBuilder from '../components/UtmBuilder';
import PlanLimitNotice from '../components/PlanLimitNotice';
import { planLimitOf } from '../utils/planLimit';
import type { CreateLinkPayload, UpdateLinkPayload } from '../api/links';
import type { PlanLimitPayload } from '../types';

interface FormValues {
    target_url: string;
    short_code: string;
    /** datetime-local format: YYYY-MM-DDTHH:mm */
    expires_at: string;
    password: string;
    removePassword: boolean;
}

const emptyValues: FormValues = {
    target_url: '',
    short_code: '',
    expires_at: '',
    password: '',
    removePassword: false,
};

const validationSchema = Yup.object({
    target_url: Yup.string().url('Введіть повний URL, напр. https://example.com').required("URL є обов'язковим"),
    short_code: Yup.string()
        .matches(/^[a-zA-Z0-9_-]*$/, 'Лише латинські літери, цифри, "-" та "_"')
        .max(64),
    password: Yup.string().min(4, 'Щонайменше 4 символи').max(255),
});

/** ISO timestamp -> the YYYY-MM-DDTHH:mm a datetime-local input expects. */
function toLocalInput(iso: string | null): string {
    if (!iso) {
        return '';
    }
    const date = new Date(iso);
    const offsetMs = date.getTimezoneOffset() * 60_000;

    return new Date(date.getTime() - offsetMs).toISOString().slice(0, 16);
}

function toIso(local: string): string | null {
    return local ? new Date(local).toISOString() : null;
}

function LinkFormPage() {
    const { siteId, linkId } = useParams<{ siteId?: string; linkId?: string }>();
    const navigate = useNavigate();
    const isEditing = Boolean(linkId);

    const [initialValues, setInitialValues] = useState<FormValues>(emptyValues);
    const [loading, setLoading] = useState(isEditing);
    const [hasPassword, setHasPassword] = useState(false);
    const [ownerSiteId, setOwnerSiteId] = useState<number | null>(siteId ? Number(siteId) : null);
    const [planLimit, setPlanLimit] = useState<PlanLimitPayload | null>(null);

    useEffect(() => {
        if (!linkId) {
            return;
        }
        getLink(Number(linkId))
            .then((link) => {
                setInitialValues({
                    ...emptyValues,
                    target_url: link.target_url,
                    short_code: link.short_code,
                    expires_at: toLocalInput(link.expires_at),
                });
                setHasPassword(link.has_password);
                setOwnerSiteId(link.site_id);
            })
            .catch((error) => {
                toast.error(errorMessage(error, 'Помилка при завантаженні посилання.'));
                navigate('/sites');
            })
            .finally(() => setLoading(false));
    }, [linkId, navigate]);

    const onSubmit = async (
        values: FormValues,
        { setSubmitting, setErrors }: { setSubmitting: (v: boolean) => void; setErrors: (e: Record<string, string>) => void }
    ) => {
        try {
            if (isEditing && linkId) {
                const payload: UpdateLinkPayload = {
                    target_url: values.target_url,
                    expires_at: toIso(values.expires_at),
                };
                // Omitted key = leave the existing password alone; '' = remove it.
                if (values.removePassword) {
                    payload.password = '';
                } else if (values.password) {
                    payload.password = values.password;
                }

                await updateLink(Number(linkId), payload);
                toast.success('Посилання оновлено.');
            } else if (siteId) {
                const payload: CreateLinkPayload = {
                    target_url: values.target_url,
                    short_code: values.short_code || undefined,
                    expires_at: toIso(values.expires_at),
                };
                if (values.password) {
                    payload.password = values.password;
                }

                await createLink(Number(siteId), payload);
                toast.success('Посилання додано.');
            }
            navigate(`/sites/${ownerSiteId ?? siteId}/links`);
        } catch (error) {
            const limit = planLimitOf(error);

            if (limit) {
                // Not a mistake in the form: the workspace is full. Say so where it can be read.
                setPlanLimit(limit);
            } else {
                setPlanLimit(null);
                setErrors(validationErrors(error));
                toast.error(errorMessage(error, 'Помилка при збереженні посилання.'));
            }
            setSubmitting(false);
        }
    };

    if (loading) {
        return (
            <Container maxWidth="sm" sx={{ mt: 4 }}>
                <Typography>Завантаження...</Typography>
            </Container>
        );
    }

    return (
        <Container maxWidth="sm" sx={{ mt: 4, mb: 6 }}>
            <Typography variant="h4" gutterBottom>
                {isEditing ? 'Редагувати посилання' : 'Додати посилання'}
            </Typography>

            {planLimit && <PlanLimitNotice message={planLimit.message} workspaceId={planLimit.workspace_id} sx={{ mb: 2 }} />}

            <Formik
                enableReinitialize
                initialValues={initialValues}
                validationSchema={validationSchema}
                onSubmit={onSubmit}
            >
                {({ isSubmitting, errors, handleChange, touched, values, setFieldValue }) => (
                    <Form>
                        <TextField
                            fullWidth
                            margin="normal"
                            label="Цільовий URL"
                            name="target_url"
                            placeholder="https://example.com/target"
                            value={values.target_url}
                            onChange={handleChange}
                            error={touched.target_url && Boolean(errors.target_url)}
                            helperText={touched.target_url && errors.target_url}
                        />

                        <UtmBuilder url={values.target_url} onChange={(url) => setFieldValue('target_url', url)} />

                        {!isEditing && (
                            <Box sx={{ mb: 1 }}>
                                <TextField
                                    fullWidth
                                    margin="normal"
                                    label="Короткий код (необов'язково)"
                                    name="short_code"
                                    placeholder="my-promo"
                                    value={values.short_code}
                                    onChange={handleChange}
                                    error={touched.short_code && Boolean(errors.short_code)}
                                    helperText={touched.short_code && errors.short_code}
                                />
                                <Alert severity="info" sx={{ mt: 1 }}>
                                    Залиш порожнім, щоб код згенерувався автоматично.
                                </Alert>
                            </Box>
                        )}

                        {isEditing && (
                            <Alert severity="info" sx={{ mt: 1, mb: 2 }}>
                                Короткий код не можна змінити після створення — поділені посилання
                                продовжать працювати.
                            </Alert>
                        )}

                        <Divider sx={{ my: 3 }}>Обмеження доступу</Divider>

                        <TextField
                            fullWidth
                            margin="normal"
                            type="datetime-local"
                            label="Діє до (необов'язково)"
                            name="expires_at"
                            value={values.expires_at}
                            onChange={handleChange}
                            slotProps={{ inputLabel: { shrink: true } }}
                            helperText="Після цього моменту посилання поверне 410 замість переходу. Порожнє — діє безстроково."
                        />

                        <TextField
                            fullWidth
                            margin="normal"
                            type="password"
                            label={hasPassword ? 'Новий пароль' : "Пароль (необов'язково)"}
                            name="password"
                            autoComplete="new-password"
                            value={values.password}
                            onChange={handleChange}
                            disabled={values.removePassword}
                            error={touched.password && Boolean(errors.password)}
                            helperText={
                                (touched.password && errors.password) ||
                                (hasPassword
                                    ? 'Пароль уже встановлено. Введіть новий, щоб змінити, або лишіть порожнім — залишиться поточний.'
                                    : 'Перед переходом відвідувач має ввести цей пароль.')
                            }
                        />

                        {hasPassword && (
                            <FormControlLabel
                                control={
                                    <Checkbox
                                        name="removePassword"
                                        checked={values.removePassword}
                                        onChange={handleChange}
                                    />
                                }
                                label="Прибрати пароль — зробити посилання відкритим"
                            />
                        )}

                        <Button
                            variant="contained"
                            color="primary"
                            type="submit"
                            disabled={isSubmitting}
                            sx={{ mt: 3 }}
                            fullWidth
                        >
                            Зберегти
                        </Button>
                    </Form>
                )}
            </Formik>
        </Container>
    );
}

export default LinkFormPage;
