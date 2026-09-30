import { useCallback, useEffect, useState } from 'react';
import { Link as RouterLink, useNavigate, useParams } from 'react-router-dom';
import { Formik, Form } from 'formik';
import * as Yup from 'yup';
import { toast } from 'react-toastify';
import {
    Container,
    Typography,
    Table,
    TableHead,
    TableBody,
    TableRow,
    TableCell,
    TableContainer,
    Paper,
    Button,
    Box,
    Alert,
    Breadcrumbs,
    Link,
    MenuItem,
    Select,
    Stack,
    TextField,
} from '@mui/material';
import { useAuth } from '../contexts/useAuth';
import { getWorkspace, listMembers, addMember, setMemberRole, removeMember } from '../api/workspaces';
import { errorMessage, validationErrors } from '../api/errors';
import { isOwner, roleDescriptions, roleLabels } from '../utils/roles';
import PlanLimitNotice from '../components/PlanLimitNotice';
import { planLimitOf } from '../utils/planLimit';
import type { Member, PlanLimitPayload, Workspace, WorkspaceRole } from '../types';

const roles: WorkspaceRole[] = ['owner', 'editor', 'viewer'];

const validationSchema = Yup.object({
    email: Yup.string().email('Некоректний email').required("Email є обов'язковим"),
    role: Yup.string().oneOf(roles).required(),
});

function WorkspaceMembersPage() {
    const { workspaceId } = useParams<{ workspaceId: string }>();
    const { user } = useAuth();
    const navigate = useNavigate();
    const id = Number(workspaceId);
    const isDemo = Boolean(user?.is_demo);

    const [workspace, setWorkspace] = useState<Workspace | null>(null);
    const [members, setMembers] = useState<Member[]>([]);
    const [loading, setLoading] = useState(true);
    const [planLimit, setPlanLimit] = useState<PlanLimitPayload | null>(null);

    const fetchData = useCallback(async () => {
        setLoading(true);
        try {
            const [workspaceData, membersData] = await Promise.all([getWorkspace(id), listMembers(id)]);
            setWorkspace(workspaceData);
            setMembers(membersData);
        } catch (error) {
            toast.error(errorMessage(error, 'Помилка при завантаженні учасників.'));
        } finally {
            setLoading(false);
        }
    }, [id]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const canManage = !isDemo && isOwner(workspace?.role);
    const ownerCount = members.filter((m) => m.role === 'owner').length;

    const handleRole = async (member: Member, role: WorkspaceRole) => {
        try {
            const updated = await setMemberRole(id, member.user_id, role);
            setMembers((prev) => prev.map((m) => (m.user_id === updated.user_id ? updated : m)));
            toast.success('Роль змінено.');
        } catch (error) {
            toast.error(
                validationErrors(error).role ?? errorMessage(error, 'Не вдалося змінити роль.')
            );
        }
    };

    const handleRemove = async (member: Member) => {
        const leaving = member.user_id === user?.id;
        const question = leaving
            ? `Вийти з workspace "${workspace?.name}"? Ви втратите доступ до його сайтів.`
            : `Прибрати ${member.name} з workspace?`;

        if (!window.confirm(question)) {
            return;
        }
        try {
            await removeMember(id, member.user_id);
            if (leaving) {
                toast.success('Ви вийшли з workspace.');
                navigate('/workspaces');
                return;
            }
            setMembers((prev) => prev.filter((m) => m.user_id !== member.user_id));
            toast.success('Учасника прибрано.');
        } catch (error) {
            toast.error(validationErrors(error).role ?? errorMessage(error, 'Не вдалося прибрати учасника.'));
        }
    };

    return (
        <Container maxWidth="md" sx={{ mt: 4, mb: 6 }}>
            <Breadcrumbs sx={{ mb: 2 }}>
                <Link component={RouterLink} to="/workspaces" underline="hover">
                    Workspaces
                </Link>
                <Typography color="text.primary">{workspace?.name ?? '...'}</Typography>
            </Breadcrumbs>

            <Typography variant="h4" gutterBottom>
                Учасники
            </Typography>

            {isDemo && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Демо-акаунт лише для читання — керувати учасниками не можна.
                </Alert>
            )}
            {!isDemo && workspace && !isOwner(workspace.role) && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Учасниками керують власники workspace. Ви можете лише вийти з нього.
                </Alert>
            )}

            {loading ? (
                <Typography>Завантаження...</Typography>
            ) : (
                <TableContainer component={Paper} sx={{ mb: 4 }}>
                    <Table>
                        <TableHead>
                            <TableRow>
                                <TableCell>Ім'я</TableCell>
                                <TableCell>Email</TableCell>
                                <TableCell>Роль</TableCell>
                                <TableCell align="right" />
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {members.map((member) => {
                                const isSelf = member.user_id === user?.id;
                                // A workspace must keep an owner; the server refuses otherwise, so don't offer it.
                                const isLastOwner = member.role === 'owner' && ownerCount <= 1;

                                return (
                                    <TableRow key={member.user_id}>
                                        <TableCell>
                                            {member.name}
                                            {isSelf && (
                                                <Typography component="span" color="text.secondary">
                                                    {' '}
                                                    (ви)
                                                </Typography>
                                            )}
                                        </TableCell>
                                        <TableCell>{member.email}</TableCell>
                                        <TableCell>
                                            {canManage ? (
                                                <Select
                                                    size="small"
                                                    value={member.role}
                                                    disabled={isLastOwner}
                                                    onChange={(e) => handleRole(member, e.target.value as WorkspaceRole)}
                                                    inputProps={{ 'aria-label': `Роль: ${member.name}` }}
                                                >
                                                    {roles.map((role) => (
                                                        <MenuItem key={role} value={role}>
                                                            {roleLabels[role]}
                                                        </MenuItem>
                                                    ))}
                                                </Select>
                                            ) : (
                                                roleLabels[member.role]
                                            )}
                                        </TableCell>
                                        <TableCell align="right">
                                            {isLastOwner ? (
                                                <Typography variant="caption" color="text.secondary">
                                                    Єдиний власник
                                                </Typography>
                                            ) : (
                                                (canManage || (isSelf && !isDemo)) && (
                                                    <Button size="small" color="error" onClick={() => handleRemove(member)}>
                                                        {isSelf ? 'Вийти' : 'Прибрати'}
                                                    </Button>
                                                )
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </TableContainer>
            )}

            {canManage && (
                <Paper sx={{ p: 3 }}>
                    <Typography variant="h6" gutterBottom>
                        Додати учасника
                    </Typography>
                    <Typography color="text.secondary" sx={{ mb: 2 }}>
                        Людина має вже мати акаунт LinkFleet — додаємо за email.
                    </Typography>
                    {planLimit && (
                        <PlanLimitNotice message={planLimit.message} workspaceId={planLimit.workspace_id} sx={{ mb: 2 }} />
                    )}
                    <Formik
                        initialValues={{ email: '', role: 'editor' as WorkspaceRole }}
                        validationSchema={validationSchema}
                        onSubmit={async (values, { setSubmitting, setErrors, resetForm }) => {
                            try {
                                const added = await addMember(id, values.email, values.role);
                                setMembers((prev) => [...prev, added]);
                                resetForm();
                                toast.success('Учасника додано.');
                            } catch (error) {
                                const limit = planLimitOf(error);

                                if (limit) {
                                    setPlanLimit(limit);
                                } else {
                                    setPlanLimit(null);
                                    setErrors(validationErrors(error));
                                    toast.error(errorMessage(error, 'Не вдалося додати учасника.'));
                                }
                            } finally {
                                setSubmitting(false);
                            }
                        }}
                    >
                        {({ isSubmitting, errors, touched, values, handleChange }) => (
                            <Form>
                                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} alignItems="flex-start">
                                    <TextField
                                        label="Email"
                                        name="email"
                                        value={values.email}
                                        onChange={handleChange}
                                        error={touched.email && Boolean(errors.email)}
                                        helperText={touched.email && errors.email}
                                        sx={{ flexGrow: 1 }}
                                    />
                                    <TextField
                                        select
                                        label="Роль"
                                        name="role"
                                        value={values.role}
                                        onChange={handleChange}
                                        helperText={roleDescriptions[values.role]}
                                        sx={{ minWidth: 220 }}
                                    >
                                        {roles.map((role) => (
                                            <MenuItem key={role} value={role}>
                                                {roleLabels[role]}
                                            </MenuItem>
                                        ))}
                                    </TextField>
                                    <Box sx={{ pt: 1 }}>
                                        <Button type="submit" variant="contained" disabled={isSubmitting}>
                                            Додати
                                        </Button>
                                    </Box>
                                </Stack>
                            </Form>
                        )}
                    </Formik>
                </Paper>
            )}
        </Container>
    );
}

export default WorkspaceMembersPage;
