import api from './client';
import type { Member, Workspace, WorkspaceRole } from '../types';

export const listWorkspaces = () => api.get<Workspace[]>('/api/workspaces').then((r) => r.data);

export const createWorkspace = (name: string) =>
    api.post<Workspace>('/api/workspaces', { name }).then((r) => r.data);

export const renameWorkspace = (id: number, name: string) =>
    api.put<Workspace>(`/api/workspaces/${id}`, { name }).then((r) => r.data);

export const deleteWorkspace = (id: number) => api.delete(`/api/workspaces/${id}`);

export const getWorkspace = (id: number) =>
    api.get<Workspace>(`/api/workspaces/${id}`).then((r) => r.data);

export const listMembers = (workspaceId: number) =>
    api.get<Member[]>(`/api/workspaces/${workspaceId}/members`).then((r) => r.data);

export const addMember = (workspaceId: number, email: string, role: WorkspaceRole) =>
    api.post<Member>(`/api/workspaces/${workspaceId}/members`, { email, role }).then((r) => r.data);

export const setMemberRole = (workspaceId: number, userId: number, role: WorkspaceRole) =>
    api
        .patch<Member>(`/api/workspaces/${workspaceId}/members/${userId}`, { role })
        .then((r) => r.data);

export const removeMember = (workspaceId: number, userId: number) =>
    api.delete(`/api/workspaces/${workspaceId}/members/${userId}`);
