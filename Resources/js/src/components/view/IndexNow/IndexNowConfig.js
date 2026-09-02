import React from "react";
import {observer} from "mobx-react";
import {action, observable} from "mobx";
import {Loader} from "sulu-admin-bundle/components";
import {withToolbar} from "sulu-admin-bundle/containers";
import {translate} from "sulu-admin-bundle/utils";
import {Requester} from "sulu-admin-bundle/services";
import {SnackbarService} from "sulu-base-bundle";

@observer
class IndexNowConfig extends React.Component {
    @observable loading = false;
    @observable data = {
        urls: [],
        submitted: null,
        submittedAt: null,
        summary: null,
    };

    componentDidMount() {
        this.loadUrls().then();
    }

    @action loadUrls = () => {
        this.loading = true;

        return Requester.get("/admin/api/index-now/urls")
            .then(action((response) => {
                this.data.urls = Array.isArray(response.urls) ? response.urls : [];
            }))
            .catch((e) => {
                console.error("Error while loading IndexNow URLs.", e);
            })
            .finally(action(() => {
                this.loading = false;
            }));
    };

    @action indexNow = () => {
        this.loading = true;

        return Requester.post("/admin/api/index-now/start")
            .then(action((response) => {
                this.data.urls = Array.isArray(response.urls) ? response.urls : this.data.urls;
                this.data.submitted = response.submitted ?? this.data.urls.length;
                this.data.submittedAt = response.submittedAt || null;
                this.data.summary = response.summary || null;

                const summary = this.data.summary;
                const failedEngines = summary?.failedEngines || 0;
                const submitted = this.data.submitted;

                SnackbarService.show({
                    text: failedEngines > 0
                        ? translate("app.index_now_partial", {count: submitted, failed: failedEngines})
                        : translate("app.index_now_submitted", {count: submitted}),
                    type: failedEngines > 0 ? "error" : "success",
                });
            }))
            .catch((e) => {
                console.error("Error while submitting IndexNow URLs.", e);
                SnackbarService.show({
                    text: e?.message || translate("app.index_now_submit_error"),
                    type: "error",
                });
            })
            .finally(action(() => {
                this.loading = false;
            }));
    };

    formatDate = (value) => {
        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return new Intl.DateTimeFormat(undefined, {
            dateStyle: "short",
            timeStyle: "short",
        }).format(date);
    };

    render() {
        const urlCount = this.data.urls?.length || 0;
        const summary = this.data.summary;
        const engines = summary?.engines || [];
        const totalEngines = (summary?.successfulEngines || 0) + (summary?.failedEngines || 0);

        return (
            <div>
                <p>{translate("app.index_now_url_count", {count: urlCount})}</p>

                {summary && (
                    <div>
                        <p>{translate("app.index_now_submitted", {count: this.data.submitted || 0})}</p>
                        <p>
                            {translate("app.index_now_provider_status", {
                                successful: summary.successfulEngines || 0,
                                total: totalEngines,
                            })}
                        </p>
                        {this.data.submittedAt && (
                            <p>
                                {translate("app.index_now_last_run", {
                                    date: this.formatDate(this.data.submittedAt),
                                })}
                            </p>
                        )}

                        {engines.length > 0 && (
                            <details>
                                <summary>{translate("app.index_now_show_details")}</summary>
                                <ul>
                                    {engines.map((engine) => (
                                        <li key={engine.name}>
                                            <strong>{engine.name}</strong>{": "}
                                            {translate(
                                                engine.status === "success"
                                                    ? "app.index_now_provider_success"
                                                    : "app.index_now_provider_failed",
                                            )}
                                            {engine.totalBatches > 1 && (
                                                <span>
                                                    {" ("}
                                                    {translate("app.index_now_batch_status", {
                                                        successful: engine.successfulBatches,
                                                        total: engine.totalBatches,
                                                    })}
                                                    {")"}
                                                </span>
                                            )}
                                            {engine.errors.length > 0 && (
                                                <ul>
                                                    {engine.errors.map((error) => <li key={error}>{error}</li>)}
                                                </ul>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        )}
                    </div>
                )}

                {this.loading && <Loader/>}
            </div>
        );
    }
}

export default withToolbar(IndexNowConfig, function () {
    return {
        items: [
            {
                type: "button",
                label: translate("app.index_now_start"),
                icon: "su-sync",
                disabled: this.loading,
                onClick: () => {
                    this.indexNow().then();
                },
            },
        ],
    };
});
