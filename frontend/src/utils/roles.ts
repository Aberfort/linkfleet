import type { WorkspaceRole } from '../types';

/** Create and change sites and links. */
export const canEdit = (role?: WorkspaceRole): boolean => role === 'owner' || role === 'editor';

/** Members, domains, webhooks and destructive actions. */
export const isOwner = (role?: WorkspaceRole): boolean => role === 'owner';

export const roleLabels: Record<WorkspaceRole, string> = {
    owner: 'Власник',
    editor: 'Редактор',
    viewer: 'Глядач',
};

export const roleDescriptions: Record<WorkspaceRole, string> = {
    owner: 'Усе, зокрема учасники, домени та видалення',
    editor: 'Створює й змінює сайти та посилання',
    viewer: 'Лише перегляд і аналітика',
};
